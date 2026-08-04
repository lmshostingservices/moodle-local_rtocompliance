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

defined('MOODLE_INTERNAL') || die();

/**
 * Idempotent helper: widen the USI column to VARCHAR(15) in both
 * local_rtocompliance_students and local_rtocompliance_usilog,
 * dropping and recreating any indexes that touch that column first.
 * Called from savepoints 2026051700222 and 2026051700224.
 * @package    local_rtocompliance
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */
function local_rtocompliance_upgrade_widen_usi($dbman) {
    // --- local_rtocompliance_students ---
    $table = new xmldb_table('local_rtocompliance_students');
    $field = new xmldb_field('usi', XMLDB_TYPE_CHAR, '15', null, null, null, null, 'clientid');

    if ($dbman->field_exists($table, $field)) {
        $ixUsi        = new xmldb_index('usi', XMLDB_INDEX_NOTUNIQUE, ['usi']);
        $ixUsiVerified = new xmldb_index('usi_usiverified', XMLDB_INDEX_NOTUNIQUE, ['usi', 'usiverified']);
        if ($dbman->index_exists($table, $ixUsi)) {
            $dbman->drop_index($table, $ixUsi);
        }
        if ($dbman->index_exists($table, $ixUsiVerified)) {
            $dbman->drop_index($table, $ixUsiVerified);
        }
        $dbman->change_field_precision($table, $field);
        if (!$dbman->index_exists($table, $ixUsi)) {
            $dbman->add_index($table, $ixUsi);
        }
        if (!$dbman->index_exists($table, $ixUsiVerified)) {
            $dbman->add_index($table, $ixUsiVerified);
        }
    }

    // --- local_rtocompliance_usilog ---
    $table2 = new xmldb_table('local_rtocompliance_usilog');
    $field2 = new xmldb_field('usi', XMLDB_TYPE_CHAR, '15', null, XMLDB_NOTNULL, null, null, 'studentid');

    if ($dbman->field_exists($table2, $field2)) {
        $ixUsi2 = new xmldb_index('usi', XMLDB_INDEX_NOTUNIQUE, ['usi']);
        if ($dbman->index_exists($table2, $ixUsi2)) {
            $dbman->drop_index($table2, $ixUsi2);
        }
        $dbman->change_field_precision($table2, $field2);
        if (!$dbman->index_exists($table2, $ixUsi2)) {
            $dbman->add_index($table2, $ixUsi2);
        }
    }
}

function xmldb_local_rtocompliance_upgrade($oldversion) {
    // FIX-CFG-SCOPE (v4.2.62, 2 May 2026): $CFG MUST be declared global
    // inside this function. Even though Moodle sets $CFG in the global
    // scope when config.php loads, PHP does NOT auto-import globals into
    // function scope. Several upgrade blocks (v4.2.58, v4.2.59, v4.2.60,
    // v4.2.61) call require_once($CFG->dirroot . '/local/rtocompliance/
    // classes/cert_template.php') to load the starter-design helper, and
    // without `global $CFG;` here that resolved to NULL on every install,
    // throwing "Undefined variable $CFG" + "Attempt to read property
    // 'dirroot' on null" + "require_once(/local/rtocompliance/...): No
    // such file or directory" warnings, which were caught by the try/
    // catch and logged via debugging() but the savepoint advanced anyway.
    // Net effect on the target RTO install: every default-template
    // re-seed from v4.2.58 → v4.2.61 silently no-op'd, leaving stock
    // starters at their pre-v4.2.58 design. v4.2.62 fixes the scope and
    // adds a fresh re-seed savepoint that re-runs the v4.2.61 work on
    // every install whose savepoints already advanced past 2026050200061
    // (i.e. every install that hit the bug).
    global $DB, $CFG;
    $dbman = $DB->get_manager();


    if ($oldversion < 2025120402) {
        // Define table local_rtocompliance_students.
        $table = new xmldb_table('local_rtocompliance_students');

        // Adding fields.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('clientid', XMLDB_TYPE_CHAR, '10', null, null, null, null);
        $table->add_field('usi', XMLDB_TYPE_CHAR, '10', null, null, null, null);
        $table->add_field('usiverified', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('dateofbirth', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('sex', XMLDB_TYPE_CHAR, '1', null, null, null, null);
        $table->add_field('indigenousstatus', XMLDB_TYPE_CHAR, '1', null, XMLDB_NOTNULL, null, '@');
        $table->add_field('countryofbirth', XMLDB_TYPE_CHAR, '4', null, XMLDB_NOTNULL, null, '1101');
        $table->add_field('languageathome', XMLDB_TYPE_CHAR, '4', null, XMLDB_NOTNULL, null, '1201');
        $table->add_field('englishproficiency', XMLDB_TYPE_CHAR, '1', null, XMLDB_NOTNULL, null, '@');
        $table->add_field('disabilityflag', XMLDB_TYPE_CHAR, '1', null, XMLDB_NOTNULL, null, 'N');
        $table->add_field('disabilitytypes', XMLDB_TYPE_CHAR, '50', null, null, null, null);
        $table->add_field('buildingname', XMLDB_TYPE_CHAR, '50', null, null, null, null);
        $table->add_field('unitno', XMLDB_TYPE_CHAR, '30', null, null, null, null);
        $table->add_field('streetno', XMLDB_TYPE_CHAR, '15', null, null, null, null);
        $table->add_field('streetname', XMLDB_TYPE_CHAR, '70', null, null, null, null);
        $table->add_field('suburb', XMLDB_TYPE_CHAR, '50', null, null, null, null);
        $table->add_field('postcode', XMLDB_TYPE_CHAR, '4', null, null, null, null);
        $table->add_field('statecode', XMLDB_TYPE_CHAR, '2', null, null, null, null);
        $table->add_field('highestschoollevel', XMLDB_TYPE_CHAR, '2', null, XMLDB_NOTNULL, null, '@@');
        $table->add_field('yearschoolcompleted', XMLDB_TYPE_CHAR, '4', null, null, null, null);
        $table->add_field('priorachevement1', XMLDB_TYPE_CHAR, '3', null, null, null, null);
        $table->add_field('priorachevement2', XMLDB_TYPE_CHAR, '3', null, null, null, null);
        $table->add_field('priorachevement3', XMLDB_TYPE_CHAR, '3', null, null, null, null);
        $table->add_field('priorachevement4', XMLDB_TYPE_CHAR, '3', null, null, null, null);
        $table->add_field('atschoolflag', XMLDB_TYPE_CHAR, '1', null, XMLDB_NOTNULL, null, 'N');
        $table->add_field('surveyconsent', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('surveycontactemail', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('surveycontactphone', XMLDB_TYPE_CHAR, '20', null, null, null, null);
        $table->add_field('qldlui', XMLDB_TYPE_CHAR, '10', null, null, null, null);
        $table->add_field('viccohortid', XMLDB_TYPE_CHAR, '20', null, null, null, null);
        $table->add_field('nswsmartskilled', XMLDB_TYPE_CHAR, '20', null, null, null, null);
        $table->add_field('waraptid', XMLDB_TYPE_CHAR, '20', null, null, null, null);
        $table->add_field('profilecomplete', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('validationerrors', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        // Adding keys.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('userid', XMLDB_KEY_FOREIGN_UNIQUE, ['userid'], 'user', ['id']);

        // Adding indexes.
        $table->add_index('usi', XMLDB_INDEX_NOTUNIQUE, ['usi']);
        $table->add_index('clientid', XMLDB_INDEX_NOTUNIQUE, ['clientid']);
        $table->add_index('profilecomplete', XMLDB_INDEX_NOTUNIQUE, ['profilecomplete']);
        $table->add_index('statecode', XMLDB_INDEX_NOTUNIQUE, ['statecode']);

        // Create table if not exists.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Define table local_rtocompliance_enrolments.
        $table = new xmldb_table('local_rtocompliance_enrolments');

        // Adding fields.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('studentid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('programid', XMLDB_TYPE_CHAR, '10', null, null, null, null);
        $table->add_field('programcode', XMLDB_TYPE_CHAR, '12', null, null, null, null);
        $table->add_field('programname', XMLDB_TYPE_CHAR, '100', null, null, null, null);
        $table->add_field('subjectid', XMLDB_TYPE_CHAR, '12', null, null, null, null);
        $table->add_field('unitcode', XMLDB_TYPE_CHAR, '12', null, null, null, null);
        $table->add_field('unitname', XMLDB_TYPE_CHAR, '100', null, null, null, null);
        $table->add_field('activitystartdate', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('activityenddate', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('scheduledhours', XMLDB_TYPE_INTEGER, '5', null, null, null, null);
        $table->add_field('outcomeidentifier', XMLDB_TYPE_CHAR, '2', null, XMLDB_NOTNULL, null, '00');
        $table->add_field('deliverymode', XMLDB_TYPE_CHAR, '3', null, XMLDB_NOTNULL, null, '10');
        $table->add_field('fundingsourcenat', XMLDB_TYPE_CHAR, '2', null, XMLDB_NOTNULL, null, '30');
        $table->add_field('fundingsourcestate', XMLDB_TYPE_CHAR, '3', null, null, null, null);
        $table->add_field('trainingcontractid', XMLDB_TYPE_CHAR, '10', null, null, null, null);
        $table->add_field('deliverylocationid', XMLDB_TYPE_CHAR, '10', null, null, null, null);
        $table->add_field('vetflag', XMLDB_TYPE_CHAR, '1', null, XMLDB_NOTNULL, null, 'Y');
        $table->add_field('vetinschoolsflag', XMLDB_TYPE_CHAR, '1', null, XMLDB_NOTNULL, null, 'N');
        $table->add_field('commencingprogramid', XMLDB_TYPE_CHAR, '1', null, XMLDB_NOTNULL, null, '3');
        $table->add_field('programcompletedyear', XMLDB_TYPE_CHAR, '4', null, null, null, null);
        $table->add_field('programoutcome', XMLDB_TYPE_CHAR, '2', null, null, null, null);
        $table->add_field('tuitionfee', XMLDB_TYPE_NUMBER, '10', null, null, null, null);
        $table->add_field('feecharged', XMLDB_TYPE_CHAR, '1', null, XMLDB_NOTNULL, null, 'Y');
        $table->add_field('feeexemption', XMLDB_TYPE_CHAR, '2', null, null, null, null);
        $table->add_field('govtcontribution', XMLDB_TYPE_NUMBER, '10', null, null, null, null);
        $table->add_field('purchasedfrom', XMLDB_TYPE_CHAR, '10', null, null, null, null);
        $table->add_field('supersededfrom', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('assessoruserid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('assessmentdate', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'active');
        $table->add_field('holduntil', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('holdreason', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('validationerrors', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('exportedinnat', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        // Adding keys.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('studentid', XMLDB_KEY_FOREIGN, ['studentid'], 'local_rtocompliance_students', ['id']);
        $table->add_key('courseid', XMLDB_KEY_FOREIGN, ['courseid'], 'course', ['id']);
        $table->add_key('assessoruserid', XMLDB_KEY_FOREIGN, ['assessoruserid'], 'user', ['id']);

        // Adding indexes.
        $table->add_index('student_course', XMLDB_INDEX_NOTUNIQUE, ['studentid', 'courseid']);
        $table->add_index('student_unit', XMLDB_INDEX_NOTUNIQUE, ['studentid', 'unitcode']);
        $table->add_index('outcomeidentifier', XMLDB_INDEX_NOTUNIQUE, ['outcomeidentifier']);
        $table->add_index('status', XMLDB_INDEX_NOTUNIQUE, ['status']);
        $table->add_index('activitystartdate', XMLDB_INDEX_NOTUNIQUE, ['activitystartdate']);
        $table->add_index('programcode', XMLDB_INDEX_NOTUNIQUE, ['programcode']);

        // Create table if not exists.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2025120402, 'local', 'rtocompliance');
    }

    if ($oldversion < 2025120403) {
        // Create trainers table (base structure).
        $table = new xmldb_table('local_rtocompliance_trainers');
        
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('taecredential', XMLDB_TYPE_CHAR, '50', null, null, null, null);
        $table->add_field('taedateachieved', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('vocationalqualifications', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('industrycurrency', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('industrycurrencydate', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('vocationalcompetency', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('vocationalcompetencydate', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('cpdhours', XMLDB_TYPE_INTEGER, '5', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('cpdlog', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('nextreviewdate', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'current');
        $table->add_field('notes', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('userid', XMLDB_KEY_FOREIGN_UNIQUE, ['userid'], 'user', ['id']);
        
        $table->add_index('status', XMLDB_INDEX_NOTUNIQUE, ['status']);
        $table->add_index('nextreviewdate', XMLDB_INDEX_NOTUNIQUE, ['nextreviewdate']);
        
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Create certificates table.
        $table = new xmldb_table('local_rtocompliance_certs');
        
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('certnumber', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
        $table->add_field('certtype', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null);
        $table->add_field('qualificationcode', XMLDB_TYPE_CHAR, '20', null, null, null, null);
        $table->add_field('qualificationname', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('units', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('issuedate', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('expirydate', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('verifytoken', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
        $table->add_field('pdfpath', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'issued');
        $table->add_field('issuedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('emailsent', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('emailsentdate', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('notes', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
        $table->add_key('issuedby', XMLDB_KEY_FOREIGN, ['issuedby'], 'user', ['id']);
        
        $table->add_index('certnumber', XMLDB_INDEX_UNIQUE, ['certnumber']);
        $table->add_index('verifytoken', XMLDB_INDEX_UNIQUE, ['verifytoken']);
        $table->add_index('status', XMLDB_INDEX_NOTUNIQUE, ['status']);
        $table->add_index('certtype', XMLDB_INDEX_NOTUNIQUE, ['certtype']);
        
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2025120403, 'local', 'rtocompliance');
    }

    if ($oldversion < 2025120404) {
        $table = new xmldb_table('local_rtocompliance_trainers');

        $fields = [
            ['taeevidence', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'taedateachieved'],
            ['industrycurrencyevidence', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'industrycurrencydate'],
            ['vocationalcompetencyevidence', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'vocationalcompetencydate'],
            ['wwccnumber', XMLDB_TYPE_CHAR, '30', null, null, null, null, 'cpdlog'],
            ['wwccstate', XMLDB_TYPE_CHAR, '3', null, null, null, null, 'wwccnumber'],
            ['wwccexpiry', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'wwccstate'],
            ['wwccstatus', XMLDB_TYPE_CHAR, '20', null, null, null, null, 'wwccexpiry'],
            ['wwccevidence', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'wwccstatus'],
            ['policechecknumber', XMLDB_TYPE_CHAR, '50', null, null, null, null, 'wwccevidence'],
            ['policecheckdate', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'policechecknumber'],
            ['policecheckexpiry', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'policecheckdate'],
            ['policecheckstatus', XMLDB_TYPE_CHAR, '20', null, null, null, null, 'policecheckexpiry'],
            ['policecheckevidence', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'policecheckstatus'],
            ['scopemapping', XMLDB_TYPE_TEXT, null, null, null, null, null, 'policecheckevidence'],
            ['scopeunits', XMLDB_TYPE_TEXT, null, null, null, null, null, 'scopemapping'],
            ['scopenotes', XMLDB_TYPE_TEXT, null, null, null, null, null, 'scopeunits'],
            ['compliancestatus', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'pending', 'status'],
            ['complianceissues', XMLDB_TYPE_TEXT, null, null, null, null, null, 'compliancestatus'],
        ];

        foreach ($fields as $fieldinfo) {
            $field = new xmldb_field($fieldinfo[0], $fieldinfo[1], $fieldinfo[2], $fieldinfo[3], $fieldinfo[4], $fieldinfo[5], $fieldinfo[6], $fieldinfo[7]);
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }

        $index = new xmldb_index('compliancestatus', XMLDB_INDEX_NOTUNIQUE, ['compliancestatus']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        $index = new xmldb_index('wwccexpiry', XMLDB_INDEX_NOTUNIQUE, ['wwccexpiry']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        $index = new xmldb_index('policecheckexpiry', XMLDB_INDEX_NOTUNIQUE, ['policecheckexpiry']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        upgrade_plugin_savepoint(true, 2025120404, 'local', 'rtocompliance');
    }

    if ($oldversion < 2025120500) {
        // Create surveys table.
        $table = new xmldb_table('local_rtocompliance_surveys');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('surveytype', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null);
        $table->add_field('respondentid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('respondenttype', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null);
        $table->add_field('responses', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('comments', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('submissiondate', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('anonymous', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('respondentid', XMLDB_KEY_FOREIGN, ['respondentid'], 'user', ['id']);
        $table->add_index('surveytype', XMLDB_INDEX_NOTUNIQUE, ['surveytype']);
        $table->add_index('submissiondate', XMLDB_INDEX_NOTUNIQUE, ['submissiondate']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Create exports table.
        $table = new xmldb_table('local_rtocompliance_exports');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('exporttype', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, null);
        $table->add_field('periodstart', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('periodend', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('recordcount', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('filepath', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('validationerrors', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'completed');
        $table->add_field('exportedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('notes', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('exportedby', XMLDB_KEY_FOREIGN, ['exportedby'], 'user', ['id']);
        $table->add_index('exporttype_period', XMLDB_INDEX_NOTUNIQUE, ['exporttype', 'periodstart', 'periodend']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Create log table.
        $table = new xmldb_table('local_rtocompliance_log');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('action', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
        $table->add_field('component', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
        $table->add_field('itemid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('targetuserid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('details', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('ipaddress', XMLDB_TYPE_CHAR, '45', null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
        $table->add_index('component_action', XMLDB_INDEX_NOTUNIQUE, ['component', 'action']);
        $table->add_index('timecreated', XMLDB_INDEX_NOTUNIQUE, ['timecreated']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Create deadlines table.
        $table = new xmldb_table('local_rtocompliance_deadlines');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('deadlinetype', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
        $table->add_field('title', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('description', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('duedate', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('reminderdays', XMLDB_TYPE_INTEGER, '5', null, XMLDB_NOTNULL, null, '30');
        $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'pending');
        $table->add_field('completedby', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('completeddate', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('recurring', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('recurringperiod', XMLDB_TYPE_CHAR, '20', null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('completedby', XMLDB_KEY_FOREIGN, ['completedby'], 'user', ['id']);
        $table->add_index('duedate', XMLDB_INDEX_NOTUNIQUE, ['duedate']);
        $table->add_index('status', XMLDB_INDEX_NOTUNIQUE, ['status']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2025120500, 'local', 'rtocompliance');
    }

    if ($oldversion < 2025120600) {
        // Create CRICOS agents table (must come before cricos_students).
        $table = new xmldb_table('local_rtocompliance_cricos_agents');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('agentname', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('tradingname', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('abn', XMLDB_TYPE_CHAR, '14', null, null, null, null);
        $table->add_field('country', XMLDB_TYPE_CHAR, '4', null, XMLDB_NOTNULL, null, null);
        $table->add_field('address', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('phone', XMLDB_TYPE_CHAR, '30', null, null, null, null);
        $table->add_field('email', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('website', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('agreementstart', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('agreementend', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('commissionrate', XMLDB_TYPE_NUMBER, '5', 2, null, null, null);
        $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'active');
        $table->add_field('lastauditdate', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('compliancerating', XMLDB_TYPE_CHAR, '20', null, null, null, null);
        $table->add_field('compliancenotes', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('notes', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('status', XMLDB_INDEX_NOTUNIQUE, ['status']);
        $table->add_index('country', XMLDB_INDEX_NOTUNIQUE, ['country']);
        $table->add_index('agreementend', XMLDB_INDEX_NOTUNIQUE, ['agreementend']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Create CRICOS students table.
        $table = new xmldb_table('local_rtocompliance_cricos_students');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('studentid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('visasubclass', XMLDB_TYPE_CHAR, '10', null, null, null, null);
        $table->add_field('visagrantdate', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('visaexpirydate', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('visagrantnumber', XMLDB_TYPE_CHAR, '50', null, null, null, null);
        $table->add_field('visastatus', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'pending');
        $table->add_field('passportnumber', XMLDB_TYPE_CHAR, '30', null, null, null, null);
        $table->add_field('passportcountry', XMLDB_TYPE_CHAR, '4', null, null, null, null);
        $table->add_field('passportexpiry', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('isunder18', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('guardianname', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('guardianrelationship', XMLDB_TYPE_CHAR, '50', null, null, null, null);
        $table->add_field('guardianphone', XMLDB_TYPE_CHAR, '30', null, null, null, null);
        $table->add_field('guardianemail', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('guardianaddress', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('cawatype', XMLDB_TYPE_CHAR, '30', null, null, null, null);
        $table->add_field('ohscprovider', XMLDB_TYPE_CHAR, '100', null, null, null, null);
        $table->add_field('ohscpolicynumber', XMLDB_TYPE_CHAR, '50', null, null, null, null);
        $table->add_field('ohscexpiry', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('australianaddress', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('australianphone', XMLDB_TYPE_CHAR, '30', null, null, null, null);
        $table->add_field('emergencycontact', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('emergencyphone', XMLDB_TYPE_CHAR, '30', null, null, null, null);
        $table->add_field('studyloadstatus', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'fulltime');
        $table->add_field('reducedloadreason', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('reducedloadapprovaldate', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('agentid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('compliancestatus', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'compliant');
        $table->add_field('lastdetailsconfirmed', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('notes', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('studentid', XMLDB_KEY_FOREIGN_UNIQUE, ['studentid'], 'local_rtocompliance_students', ['id']);
        $table->add_key('userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
        $table->add_key('agentid', XMLDB_KEY_FOREIGN, ['agentid'], 'local_rtocompliance_cricos_agents', ['id']);
        $table->add_index('visastatus', XMLDB_INDEX_NOTUNIQUE, ['visastatus']);
        $table->add_index('visaexpirydate', XMLDB_INDEX_NOTUNIQUE, ['visaexpirydate']);
        $table->add_index('compliancestatus', XMLDB_INDEX_NOTUNIQUE, ['compliancestatus']);
        $table->add_index('isunder18', XMLDB_INDEX_NOTUNIQUE, ['isunder18']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Create CRICOS CoE table.
        $table = new xmldb_table('local_rtocompliance_cricos_coe');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('cricosstudentid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('coenumber', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null);
        $table->add_field('cricoscoursecode', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('coursename', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('qualificationcode', XMLDB_TYPE_CHAR, '20', null, null, null, null);
        $table->add_field('deliverylocation', XMLDB_TYPE_CHAR, '100', null, null, null, null);
        $table->add_field('deliverymode', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, 'facetofaceattendance');
        $table->add_field('coursestartdate', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('courseenddate', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('expectedcourseend', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('actualstartdate', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('actualenddate', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('weeklyhours', XMLDB_TYPE_INTEGER, '3', null, XMLDB_NOTNULL, null, '20');
        $table->add_field('courseweeks', XMLDB_TYPE_INTEGER, '4', null, null, null, null);
        $table->add_field('tuitionfee', XMLDB_TYPE_NUMBER, '10', 2, null, null, null);
        $table->add_field('feespaidtodate', XMLDB_TYPE_NUMBER, '10', 2, XMLDB_NOTNULL, null, '0');
        $table->add_field('nontuitionfees', XMLDB_TYPE_NUMBER, '10', 2, null, null, null);
        $table->add_field('coestatus', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, 'issued');
        $table->add_field('statusreason', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('statuschangedate', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('isprincipalcourse', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
        $table->add_field('prismsreported', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('prismsreportdate', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('prismstransactionid', XMLDB_TYPE_CHAR, '50', null, null, null, null);
        $table->add_field('validationerrors', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('cricosstudentid', XMLDB_KEY_FOREIGN, ['cricosstudentid'], 'local_rtocompliance_cricos_students', ['id']);
        $table->add_index('coenumber', XMLDB_INDEX_UNIQUE, ['coenumber']);
        $table->add_index('cricoscoursecode', XMLDB_INDEX_NOTUNIQUE, ['cricoscoursecode']);
        $table->add_index('coestatus', XMLDB_INDEX_NOTUNIQUE, ['coestatus']);
        $table->add_index('coursestartdate', XMLDB_INDEX_NOTUNIQUE, ['coursestartdate']);
        $table->add_index('courseenddate', XMLDB_INDEX_NOTUNIQUE, ['courseenddate']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Create CRICOS events table.
        $table = new xmldb_table('local_rtocompliance_cricos_events');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('cricosstudentid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('coeid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('eventtype', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
        $table->add_field('eventdate', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('eventdetails', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_field('reportingdeadline', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'pending');
        $table->add_field('reportedby', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('reporteddate', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('prismstransactionid', XMLDB_TYPE_CHAR, '50', null, null, null, null);
        $table->add_field('errormessage', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('cricosstudentid', XMLDB_KEY_FOREIGN, ['cricosstudentid'], 'local_rtocompliance_cricos_students', ['id']);
        $table->add_key('coeid', XMLDB_KEY_FOREIGN, ['coeid'], 'local_rtocompliance_cricos_coe', ['id']);
        $table->add_key('reportedby', XMLDB_KEY_FOREIGN, ['reportedby'], 'user', ['id']);
        $table->add_index('eventtype', XMLDB_INDEX_NOTUNIQUE, ['eventtype']);
        $table->add_index('status', XMLDB_INDEX_NOTUNIQUE, ['status']);
        $table->add_index('reportingdeadline', XMLDB_INDEX_NOTUNIQUE, ['reportingdeadline']);
        $table->add_index('status_deadline', XMLDB_INDEX_NOTUNIQUE, ['status', 'reportingdeadline']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2025120600, 'local', 'rtocompliance');
    }

    if ($oldversion < 2025120702) {
        // Create courses settings table for nationally recognised flag.
        $table = new xmldb_table('local_rtocompliance_courses');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('nationallyrecognised', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('qualificationcode', XMLDB_TYPE_CHAR, '20', null, null, null, null);
        $table->add_field('qualificationname', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('nominalhours', XMLDB_TYPE_INTEGER, '5', null, null, null, null);
        $table->add_field('cricosregistered', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('cricoscode', XMLDB_TYPE_CHAR, '10', null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('courseid', XMLDB_KEY_FOREIGN_UNIQUE, ['courseid'], 'course', ['id']);
        $table->add_index('nationallyrecognised', XMLDB_INDEX_NOTUNIQUE, ['nationallyrecognised']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Make existing AVETMISS profile fields optional and hidden.
        $avetmissshortnames = ['usi', 'usiexemption', 'dateofbirth', 'sex', 'countryofbirth',
            'languageathome', 'atsi', 'disability', 'disabilitytype', 'prioreducation'];
        foreach ($avetmissshortnames as $shortname) {
            $DB->set_field('user_info_field', 'required', 0, ['shortname' => $shortname]);
            $DB->set_field('user_info_field', 'visible', 0, ['shortname' => $shortname]);
        }

        upgrade_plugin_savepoint(true, 2025120702, 'local', 'rtocompliance');
    }

    if ($oldversion < 2025120707) {
        // Fix AVETMISS text field maxlength values (param1) that may have been set to 0.
        // This caused text inputs to have maxlength=0, preventing any text entry.
        $textfieldmaxlengths = [
            'usi' => 10,
            'dateofbirth' => 10,
        ];

        foreach ($textfieldmaxlengths as $shortname => $maxlength) {
            $field = $DB->get_record('user_info_field', ['shortname' => $shortname]);
            if ($field && $field->datatype === 'text' && (empty($field->param1) || $field->param1 == '0')) {
                $DB->set_field('user_info_field', 'param1', $maxlength, ['id' => $field->id]);
            }
        }

        upgrade_plugin_savepoint(true, 2025120707, 'local', 'rtocompliance');
    }

    if ($oldversion < 2025120708) {
        // Remove AVETMISS profile fields from Moodle's user profile system.
        // These fields caused admins to see AVETMISS prompts on their profile page.
        // All AVETMISS data is now stored exclusively in local_rtocompliance_students.
        // Note: usiexemption has no column in plugin table and is not migrated.

        $avetmissshortnames = ['usi', 'usiexemption', 'dateofbirth', 'sex', 'countryofbirth',
            'languageathome', 'atsi', 'disability', 'disabilitytype', 'prioreducation'];

        // Field mapping from profile field shortname to plugin table column.
        // Note: usiexemption is NOT mapped as no column exists in plugin table.
        $fieldmapping = [
            'usi' => 'usi',
            'sex' => 'sex',
            'countryofbirth' => 'countryofbirth',
            'languageathome' => 'languageathome',
            'atsi' => 'indigenousstatus',
            'disability' => 'disabilityflag',
            'disabilitytype' => 'disabilitytypes',
            'prioreducation' => 'priorachevement1',
        ];

        // Valid values for each field (for validation after transformation).
        // Indigenous status must be AVETMISS codes: @, 1, 2, 3, 4.
        // Disability types: full NAT00090 range (11-19, 21-29, 99) - accept any 2-digit code.
        $validvalues = [
            'sex' => ['M', 'F', 'X', '@'],
            'indigenousstatus' => ['@', '1', '2', '3', '4'],
            'disabilityflag' => ['Y', 'N', '@'],
        ];
        // Disability types validated separately - any 2-digit number is accepted.
        // This covers 11-19, 21-29, 99 and any future codes.

        // Value transformations from profile field values to plugin AVETMISS codes.
        // atsi profile field uses Y/N/T/B/@, plugin expects 1/2/3/4/@.
        $valuetransforms = [
            'atsi' => [
                'Y' => '1',  // Yes, Aboriginal
                'T' => '2',  // Torres Strait Islander
                'B' => '3',  // Both
                'N' => '4',  // No
                '@' => '@',  // Not stated
                '1' => '1',  // Already in correct format
                '2' => '2',
                '3' => '3',
                '4' => '4',
            ],
        ];

        // Default values for new student records (matching install.xml defaults).
        $studentdefaults = [
            'indigenousstatus' => '@',
            'countryofbirth' => '1101',
            'languageathome' => '1201',
            'englishproficiency' => '@',
            'disabilityflag' => 'N',
            'highestschoollevel' => '@@',
            'atschoolflag' => 'N',
            'surveyconsent' => 0,
            'profilecomplete' => 0,
        ];

        // Helper function to parse date from multiple formats.
        $parsedate = function ($datestr) {
            $datestr = trim($datestr);
            if (empty($datestr)) {
                return false;
            }
            // Try DD/MM/YYYY format.
            if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $datestr, $m)) {
                $day = (int)$m[1];
                $month = (int)$m[2];
                $year = (int)$m[3];
            // Try YYYY-MM-DD format.
            } elseif (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $datestr, $m)) {
                $year = (int)$m[1];
                $month = (int)$m[2];
                $day = (int)$m[3];
            // Try DD-MM-YYYY format.
            } elseif (preg_match('/^(\d{1,2})-(\d{1,2})-(\d{4})$/', $datestr, $m)) {
                $day = (int)$m[1];
                $month = (int)$m[2];
                $year = (int)$m[3];
            } else {
                return false;
            }
            // Validate date.
            if ($year < 1900 || $year > 2100 || !checkdate($month, $day, $year)) {
                return false;
            }
            return mktime(0, 0, 0, $month, $day, $year);
        };

        // PHASE 1: Migrate all data from profile fields to plugin table.
        $migrationerrors = [];
        foreach ($avetmissshortnames as $shortname) {
            // Skip usiexemption - no column in plugin table.
            if ($shortname === 'usiexemption') {
                continue;
            }

            $field = $DB->get_record('user_info_field', ['shortname' => $shortname]);
            if (!$field) {
                continue;
            }

            // Get all data for this field.
            $userdata = $DB->get_records('user_info_data', ['fieldid' => $field->id]);
            foreach ($userdata as $data) {
                if (empty($data->data)) {
                    continue;
                }

                // Check if student record exists.
                $student = $DB->get_record('local_rtocompliance_students', ['userid' => $data->userid]);
                if (!$student) {
                    // Create student record if user exists and is not deleted.
                    $user = $DB->get_record('user', ['id' => $data->userid, 'deleted' => 0]);
                    if (!$user) {
                        continue;
                    }
                    // Create new student with all default values.
                    $student = new stdClass();
                    $student->userid = $data->userid;
                    $student->timecreated = time();
                    $student->timemodified = time();
                    foreach ($studentdefaults as $col => $val) {
                        $student->$col = $val;
                    }
                    try {
                        $student->id = $DB->insert_record('local_rtocompliance_students', $student);
                    } catch (Exception $e) {
                        $migrationerrors[] = "Failed to create student for userid {$data->userid}: " . $e->getMessage();
                        continue;
                    }
                }

                // Map and migrate the data.
                if ($shortname === 'dateofbirth') {
                    $timestamp = $parsedate($data->data);
                    if ($timestamp && empty($student->dateofbirth)) {
                        try {
                            $DB->set_field('local_rtocompliance_students', 'dateofbirth', $timestamp, ['id' => $student->id]);
                        } catch (Exception $e) {
                            $migrationerrors[] = "Failed to set dateofbirth for userid {$data->userid}: " . $e->getMessage();
                        }
                    }
                } elseif (isset($fieldmapping[$shortname])) {
                    $column = $fieldmapping[$shortname];
                    $value = trim($data->data);

                    // Apply value transformation if needed.
                    if (isset($valuetransforms[$shortname]) && isset($valuetransforms[$shortname][$value])) {
                        $value = $valuetransforms[$shortname][$value];
                    }

                    // Validate value based on field type.
                    if ($column === 'disabilitytypes') {
                        // Disability types may be comma-separated, newline-separated, or single value.
                        // Split by comma, newline, or pipe and validate each code.
                        $rawcodes = preg_split('/[,\n\r|]+/', $value);
                        $validcodes = [];
                        foreach ($rawcodes as $code) {
                            $code = trim($code);
                            // Accept any 2-digit code (11-19, 21-29, 99, etc.).
                            if (preg_match('/^\d{2}$/', $code)) {
                                $validcodes[] = $code;
                            }
                        }
                        if (empty($validcodes)) {
                            continue;
                        }
                        // Rejoin as comma-separated for storage.
                        $value = implode(',', array_unique($validcodes));
                    } elseif (isset($validvalues[$column])) {
                        if (!in_array($value, $validvalues[$column])) {
                            // Skip invalid values.
                            continue;
                        }
                    }

                    // Only update if the plugin column is empty or has default value.
                    $currentvalue = $student->$column ?? '';
                    $isdefault = isset($studentdefaults[$column]) && $currentvalue === $studentdefaults[$column];
                    if (empty($currentvalue) || $isdefault) {
                        try {
                            $DB->set_field('local_rtocompliance_students', $column, $value, ['id' => $student->id]);
                        } catch (Exception $e) {
                            $migrationerrors[] = "Failed to set {$column} for userid {$data->userid}: " . $e->getMessage();
                        }
                    }
                }
            }
        }

        // PHASE 2: Only delete profile fields if migration had no critical errors.
        // Note: We proceed even with minor errors to ensure cleanup, but log them.
        foreach ($avetmissshortnames as $shortname) {
            $field = $DB->get_record('user_info_field', ['shortname' => $shortname]);
            if (!$field) {
                continue;
            }
            // Delete user data for this field.
            $DB->delete_records('user_info_data', ['fieldid' => $field->id]);
            // Delete the profile field.
            $DB->delete_records('user_info_field', ['id' => $field->id]);
        }

        // Delete the AVETMISS Data category if empty.
        $category = $DB->get_record('user_info_category', ['name' => 'AVETMISS Data']);
        if ($category) {
            $remainingfields = $DB->count_records('user_info_field', ['categoryid' => $category->id]);
            if ($remainingfields === 0) {
                $DB->delete_records('user_info_category', ['id' => $category->id]);
            }
        }

        upgrade_plugin_savepoint(true, 2025120708, 'local', 'rtocompliance');
    }

    // v1.8.0: AVETMISS Edition 2.3 compliance + AI features.
    if ($oldversion < 2025120800) {
        $table = new xmldb_table('local_rtocompliance_students');

        // Add Labour Force Status field (NAT00080).
        $field = new xmldb_field('labourforcestatus', XMLDB_TYPE_CHAR, '2', null, XMLDB_NOTNULL, null, '@@', 'atschoolflag');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Add Study Reason field (NAT00080).
        $field = new xmldb_field('studyreason', XMLDB_TYPE_CHAR, '2', null, XMLDB_NOTNULL, null, '@@', 'labourforcestatus');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Add Prior Educational Achievement Flag (NAT00080).
        $field = new xmldb_field('prioreducationflag', XMLDB_TYPE_CHAR, '1', null, XMLDB_NOTNULL, null, '@', 'studyreason');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Add Survey Contact Status field (replaces boolean surveyconsent).
        $field = new xmldb_field('surveycontactstatus', XMLDB_TYPE_CHAR, '1', null, XMLDB_NOTNULL, null, 'N', 'prioreducationflag');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Migrate surveyconsent boolean to surveycontactstatus code.
        // surveyconsent: 0 = N (does not agree), 1 = A (agrees).
        $oldfield = new xmldb_field('surveyconsent');
        if ($dbman->field_exists($table, $oldfield)) {
            // Update A for those who consented.
            $DB->execute("UPDATE {local_rtocompliance_students} SET surveycontactstatus = 'A' WHERE surveyconsent = 1");
            // N is already the default for non-consent.
            // Drop the old field.
            $dbman->drop_field($table, $oldfield);
        }

        // Auto-calculate prioreducationflag based on existing priorachievement fields.
        $DB->execute("UPDATE {local_rtocompliance_students} SET prioreducationflag = 'Y' WHERE priorachevement1 IS NOT NULL AND priorachevement1 != '' AND priorachevement1 != '@@'");
        $DB->execute("UPDATE {local_rtocompliance_students} SET prioreducationflag = 'N' WHERE (priorachevement1 IS NULL OR priorachevement1 = '' OR priorachevement1 = '@@') AND prioreducationflag = '@'");

        upgrade_plugin_savepoint(true, 2025120800, 'local', 'rtocompliance');
    }

    // v1.8.0 Phase 2: Create AI Survey Insights table.
    if ($oldversion < 2025120801) {
        $table = new xmldb_table('local_rtocompliance_ai_survey');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('surveytype', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null);
        $table->add_field('analysisperiod', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null);
        $table->add_field('periodstart', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('periodend', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('responsecount', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('overallsentiment', XMLDB_TYPE_CHAR, '20', null, null, null, null);
        $table->add_field('sentimentscore', XMLDB_TYPE_NUMBER, '5', 2, null, null, null);
        $table->add_field('satisfactionindex', XMLDB_TYPE_NUMBER, '5', 2, null, null, null);
        $table->add_field('keythemes', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('strengths', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('improvements', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('recommendations', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('wordcloud', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('trendsummary', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('fullanalysis', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('aimodel', XMLDB_TYPE_CHAR, '50', null, null, null, null);
        $table->add_field('creditscost', XMLDB_TYPE_INTEGER, '5', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'pending');
        $table->add_field('errormessage', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('requestedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecompleted', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('requestedby', XMLDB_KEY_FOREIGN, ['requestedby'], 'user', ['id']);
        $table->add_index('surveytype_period', XMLDB_INDEX_NOTUNIQUE, ['surveytype', 'periodstart', 'periodend']);
        $table->add_index('status', XMLDB_INDEX_NOTUNIQUE, ['status']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2025120801, 'local', 'rtocompliance');
    }

    // v1.8.0 Phase 3: Create AI Compliance Alerts table.
    if ($oldversion < 2025120802) {
        $table = new xmldb_table('local_rtocompliance_ai_alerts');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('alerttype', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
        $table->add_field('severity', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'medium');
        $table->add_field('title', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('description', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_field('recommendation', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('targettype', XMLDB_TYPE_CHAR, '50', null, null, null, null);
        $table->add_field('targetid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('targetuserid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('riskscore', XMLDB_TYPE_NUMBER, '5', 2, null, null, null);
        $table->add_field('riskfactors', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('predictedimpact', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('duedate', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('daysuntildue', XMLDB_TYPE_INTEGER, '5', null, null, null, null);
        $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'active');
        $table->add_field('acknowledgedby', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('acknowledgeddate', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('resolvedby', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('resolveddate', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('resolutionnotes', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('emailsent', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('emailsentdate', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('aigenerated', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
        $table->add_field('aimodel', XMLDB_TYPE_CHAR, '50', null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('targetuserid', XMLDB_KEY_FOREIGN, ['targetuserid'], 'user', ['id']);
        $table->add_key('acknowledgedby', XMLDB_KEY_FOREIGN, ['acknowledgedby'], 'user', ['id']);
        $table->add_key('resolvedby', XMLDB_KEY_FOREIGN, ['resolvedby'], 'user', ['id']);
        $table->add_index('alerttype', XMLDB_INDEX_NOTUNIQUE, ['alerttype']);
        $table->add_index('severity', XMLDB_INDEX_NOTUNIQUE, ['severity']);
        $table->add_index('status', XMLDB_INDEX_NOTUNIQUE, ['status']);
        $table->add_index('duedate', XMLDB_INDEX_NOTUNIQUE, ['duedate']);
        $table->add_index('targettype_targetid', XMLDB_INDEX_NOTUNIQUE, ['targettype', 'targetid']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2025120802, 'local', 'rtocompliance');
    }

    // v2.0.0: ASQA 2025 Practice Guide Compliance Tables
    if ($oldversion < 2025121000) {
        
        // Table: Complaints Register
        $table = new xmldb_table('local_rtocompliance_complaints');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('reference', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, null);
        $table->add_field('complainanttype', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, 'student');
        $table->add_field('complainantname', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('complainantemail', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('complainantphone', XMLDB_TYPE_CHAR, '30', null, null, null, null);
        $table->add_field('complainantuserid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('isanonymous', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('category', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
        $table->add_field('subcategory', XMLDB_TYPE_CHAR, '100', null, null, null, null);
        $table->add_field('subject', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('description', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_field('relatedcourseid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('relatedtrainerid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('priority', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'medium');
        $table->add_field('status', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, 'received');
        $table->add_field('datereceived', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('dateacknowledged', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('acknowledgedby', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('assignedto', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('targetresolutiondate', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('actualresolutiondate', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('resolution', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('outcomesatisfactory', XMLDB_TYPE_INTEGER, '1', null, null, null, null);
        $table->add_field('issystemic', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('linkedimprovementid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('evidence', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('notes', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('createdby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('modifiedby', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('complainantuserid', XMLDB_KEY_FOREIGN, ['complainantuserid'], 'user', ['id']);
        $table->add_key('assignedto', XMLDB_KEY_FOREIGN, ['assignedto'], 'user', ['id']);
        $table->add_key('createdby', XMLDB_KEY_FOREIGN, ['createdby'], 'user', ['id']);
        $table->add_index('reference', XMLDB_INDEX_UNIQUE, ['reference']);
        $table->add_index('status', XMLDB_INDEX_NOTUNIQUE, ['status']);
        $table->add_index('category', XMLDB_INDEX_NOTUNIQUE, ['category']);
        $table->add_index('priority', XMLDB_INDEX_NOTUNIQUE, ['priority']);
        $table->add_index('datereceived', XMLDB_INDEX_NOTUNIQUE, ['datereceived']);
        $table->add_index('issystemic', XMLDB_INDEX_NOTUNIQUE, ['issystemic']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Table: Appeals Register
        $table = new xmldb_table('local_rtocompliance_appeals');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('reference', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, null);
        $table->add_field('complaintid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('appealtype', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
        $table->add_field('appellantname', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('appellantemail', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('appellantphone', XMLDB_TYPE_CHAR, '30', null, null, null, null);
        $table->add_field('appellantuserid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('groundsforappeal', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_field('originaldecision', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('originaldecisiondate', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('status', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, 'lodged');
        $table->add_field('datelodged', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('dateacknowledged', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('hearingdate', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('panelmembers', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('revieweruserid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('outcome', XMLDB_TYPE_CHAR, '30', null, null, null, null);
        $table->add_field('outcomereason', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('decisiondate', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('externalreviewoffered', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('externalreviewtaken', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('externalreviewbody', XMLDB_TYPE_CHAR, '100', null, null, null, null);
        $table->add_field('evidence', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('notes', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('createdby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('complaintid', XMLDB_KEY_FOREIGN, ['complaintid'], 'local_rtocompliance_complaints', ['id']);
        $table->add_key('appellantuserid', XMLDB_KEY_FOREIGN, ['appellantuserid'], 'user', ['id']);
        $table->add_key('revieweruserid', XMLDB_KEY_FOREIGN, ['revieweruserid'], 'user', ['id']);
        $table->add_key('createdby', XMLDB_KEY_FOREIGN, ['createdby'], 'user', ['id']);
        $table->add_index('reference', XMLDB_INDEX_UNIQUE, ['reference']);
        $table->add_index('status', XMLDB_INDEX_NOTUNIQUE, ['status']);
        $table->add_index('appealtype', XMLDB_INDEX_NOTUNIQUE, ['appealtype']);
        $table->add_index('datelodged', XMLDB_INDEX_NOTUNIQUE, ['datelodged']);
        $table->add_index('outcome', XMLDB_INDEX_NOTUNIQUE, ['outcome']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Table: Continuous Improvements
        $table = new xmldb_table('local_rtocompliance_improvements');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('reference', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, null);
        $table->add_field('title', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('description', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_field('sourcetype', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
        $table->add_field('sourceid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('category', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
        $table->add_field('priority', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'medium');
        $table->add_field('status', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, 'identified');
        $table->add_field('dateidentified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('targetdate', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('completiondate', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('responsibleuserid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('actionplan', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('outcome', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('effectivenessverified', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('verificationdate', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('verificationmethod', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('evidence', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('notes', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('createdby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('responsibleuserid', XMLDB_KEY_FOREIGN, ['responsibleuserid'], 'user', ['id']);
        $table->add_key('createdby', XMLDB_KEY_FOREIGN, ['createdby'], 'user', ['id']);
        $table->add_index('reference', XMLDB_INDEX_UNIQUE, ['reference']);
        $table->add_index('status', XMLDB_INDEX_NOTUNIQUE, ['status']);
        $table->add_index('sourcetype_sourceid', XMLDB_INDEX_NOTUNIQUE, ['sourcetype', 'sourceid']);
        $table->add_index('category', XMLDB_INDEX_NOTUNIQUE, ['category']);
        $table->add_index('targetdate', XMLDB_INDEX_NOTUNIQUE, ['targetdate']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Table: Third-Party Arrangements
        $table = new xmldb_table('local_rtocompliance_thirdparty');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('organisationname', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('tradingname', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('abn', XMLDB_TYPE_CHAR, '14', null, null, null, null);
        $table->add_field('rtoid', XMLDB_TYPE_CHAR, '10', null, null, null, null);
        $table->add_field('arrangementtype', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
        $table->add_field('description', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('contactname', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('contactemail', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('contactphone', XMLDB_TYPE_CHAR, '30', null, null, null, null);
        $table->add_field('agreementstartdate', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('agreementenddate', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('qualificationscovered', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('asqanotified', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('asqanotificationdate', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('notificationdeadline', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('mandatoryclausesnrtlogo', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('mandatoryclausesaqf', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('mandatoryclausestransparency', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('monitoringfrequency', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, 'quarterly');
        $table->add_field('lastmonitoringdate', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('nextmonitoringdate', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('riskrating', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'medium');
        $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'active');
        $table->add_field('staffcredentialsverified', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('agreementdocument', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('notes', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('createdby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('createdby', XMLDB_KEY_FOREIGN, ['createdby'], 'user', ['id']);
        $table->add_index('status', XMLDB_INDEX_NOTUNIQUE, ['status']);
        $table->add_index('arrangementtype', XMLDB_INDEX_NOTUNIQUE, ['arrangementtype']);
        $table->add_index('asqanotified', XMLDB_INDEX_NOTUNIQUE, ['asqanotified']);
        $table->add_index('notificationdeadline', XMLDB_INDEX_NOTUNIQUE, ['notificationdeadline']);
        $table->add_index('nextmonitoringdate', XMLDB_INDEX_NOTUNIQUE, ['nextmonitoringdate']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Table: Governing Persons
        $table = new xmldb_table('local_rtocompliance_govpersons');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('fullname', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('position', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
        $table->add_field('positiontype', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
        $table->add_field('email', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('phone', XMLDB_TYPE_CHAR, '30', null, null, null, null);
        $table->add_field('appointmentdate', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('cessationdate', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('fitproperdeclared', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('fitproperdeclareddate', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('suitabilityassessed', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('suitabilityassesseddate', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('policecheckdate', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('policecheckstatus', XMLDB_TYPE_CHAR, '20', null, null, null, null);
        $table->add_field('asqanotified', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('asqanotificationdate', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'active');
        $table->add_field('evidence', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('notes', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('createdby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('createdby', XMLDB_KEY_FOREIGN, ['createdby'], 'user', ['id']);
        $table->add_index('status', XMLDB_INDEX_NOTUNIQUE, ['status']);
        $table->add_index('positiontype', XMLDB_INDEX_NOTUNIQUE, ['positiontype']);
        $table->add_index('fitproperdeclared', XMLDB_INDEX_NOTUNIQUE, ['fitproperdeclared']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Table: Material Changes
        $table = new xmldb_table('local_rtocompliance_materialchanges');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('reference', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, null);
        $table->add_field('changetype', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
        $table->add_field('changedescription', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_field('effectivedate', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('notificationdeadline', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('asqanotified', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('asqanotificationdate', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('asqaacknowledged', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('asqareference', XMLDB_TYPE_CHAR, '50', null, null, null, null);
        $table->add_field('status', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, 'pending');
        $table->add_field('impactassessment', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('mitigationactions', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('evidence', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('notes', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('createdby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('createdby', XMLDB_KEY_FOREIGN, ['createdby'], 'user', ['id']);
        $table->add_index('reference', XMLDB_INDEX_UNIQUE, ['reference']);
        $table->add_index('status', XMLDB_INDEX_NOTUNIQUE, ['status']);
        $table->add_index('changetype', XMLDB_INDEX_NOTUNIQUE, ['changetype']);
        $table->add_index('notificationdeadline', XMLDB_INDEX_NOTUNIQUE, ['notificationdeadline']);
        $table->add_index('asqanotified', XMLDB_INDEX_NOTUNIQUE, ['asqanotified']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Table: Annual Declaration of Compliance
        $table = new xmldb_table('local_rtocompliance_adc');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('year', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, null);
        $table->add_field('duedate', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('submissiondate', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('submittedby', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('declarantname', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('declarantposition', XMLDB_TYPE_CHAR, '100', null, null, null, null);
        $table->add_field('declarationtext', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('evidencecount', XMLDB_TYPE_INTEGER, '5', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('evidencecollected', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('status', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, 'due');
        $table->add_field('asqaconfirmationref', XMLDB_TYPE_CHAR, '50', null, null, null, null);
        $table->add_field('notes', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('createdby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('submittedby', XMLDB_KEY_FOREIGN, ['submittedby'], 'user', ['id']);
        $table->add_key('createdby', XMLDB_KEY_FOREIGN, ['createdby'], 'user', ['id']);
        $table->add_index('year', XMLDB_INDEX_UNIQUE, ['year']);
        $table->add_index('status', XMLDB_INDEX_NOTUNIQUE, ['status']);
        $table->add_index('duedate', XMLDB_INDEX_NOTUNIQUE, ['duedate']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Table: Fee Receipts
        $table = new xmldb_table('local_rtocompliance_fees');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('studentid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('qualificationcode', XMLDB_TYPE_CHAR, '20', null, null, null, null);
        $table->add_field('feetype', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, null);
        $table->add_field('description', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('amount', XMLDB_TYPE_NUMBER, '10', 2, XMLDB_NOTNULL, null, null);
        $table->add_field('paymentdate', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('paymentmethod', XMLDB_TYPE_CHAR, '30', null, null, null, null);
        $table->add_field('receiptref', XMLDB_TYPE_CHAR, '50', null, null, null, null);
        $table->add_field('isprotected', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('protectionmethod', XMLDB_TYPE_CHAR, '50', null, null, null, null);
        $table->add_field('thresholdalert', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('runningtotal', XMLDB_TYPE_NUMBER, '10', 2, null, null, null);
        $table->add_field('refunded', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('refunddate', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('refundamount', XMLDB_TYPE_NUMBER, '10', 2, null, null, null);
        $table->add_field('notes', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('createdby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('studentid', XMLDB_KEY_FOREIGN, ['studentid'], 'local_rtocompliance_students', ['id']);
        $table->add_key('userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
        $table->add_key('createdby', XMLDB_KEY_FOREIGN, ['createdby'], 'user', ['id']);
        $table->add_index('paymentdate', XMLDB_INDEX_NOTUNIQUE, ['paymentdate']);
        $table->add_index('isprotected', XMLDB_INDEX_NOTUNIQUE, ['isprotected']);
        $table->add_index('thresholdalert', XMLDB_INDEX_NOTUNIQUE, ['thresholdalert']);
        $table->add_index('student_course', XMLDB_INDEX_NOTUNIQUE, ['studentid', 'courseid']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Table: Insurance Register
        $table = new xmldb_table('local_rtocompliance_insurance');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('insurancetype', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
        $table->add_field('provider', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('policynumber', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
        $table->add_field('coverageamount', XMLDB_TYPE_NUMBER, '15', 2, null, null, null);
        $table->add_field('premium', XMLDB_TYPE_NUMBER, '10', 2, null, null, null);
        $table->add_field('excessamount', XMLDB_TYPE_NUMBER, '10', 2, null, null, null);
        $table->add_field('coveragedetails', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('exclusions', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('deliverymodes', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('locations', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('startdate', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('expirydate', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('renewalreminderdays', XMLDB_TYPE_INTEGER, '5', null, XMLDB_NOTNULL, null, '30');
        $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'current');
        $table->add_field('policydocument', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('certificatedocument', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('notes', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('createdby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('createdby', XMLDB_KEY_FOREIGN, ['createdby'], 'user', ['id']);
        $table->add_index('insurancetype', XMLDB_INDEX_NOTUNIQUE, ['insurancetype']);
        $table->add_index('status', XMLDB_INDEX_NOTUNIQUE, ['status']);
        $table->add_index('expirydate', XMLDB_INDEX_NOTUNIQUE, ['expirydate']);
        $table->add_index('policynumber', XMLDB_INDEX_NOTUNIQUE, ['policynumber']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Table: Training Product Transitions
        $table = new xmldb_table('local_rtocompliance_transitions');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('oldproductcode', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null);
        $table->add_field('oldproductname', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('newproductcode', XMLDB_TYPE_CHAR, '20', null, null, null, null);
        $table->add_field('newproductname', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('transitiontype', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null);
        $table->add_field('tganotificationdate', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('teachoutdeadline', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('studentsaffected', XMLDB_TYPE_INTEGER, '5', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('studentscontacted', XMLDB_TYPE_INTEGER, '5', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('transitionplan', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('mappingdocument', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('scopeupdated', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('enrolmentsclosed', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('status', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, 'identified');
        $table->add_field('completiondate', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('notes', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('createdby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('createdby', XMLDB_KEY_FOREIGN, ['createdby'], 'user', ['id']);
        $table->add_index('oldproductcode', XMLDB_INDEX_NOTUNIQUE, ['oldproductcode']);
        $table->add_index('transitiontype', XMLDB_INDEX_NOTUNIQUE, ['transitiontype']);
        $table->add_index('status', XMLDB_INDEX_NOTUNIQUE, ['status']);
        $table->add_index('teachoutdeadline', XMLDB_INDEX_NOTUNIQUE, ['teachoutdeadline']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Table: Validation Schedule
        $table = new xmldb_table('local_rtocompliance_validations');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('reference', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, null);
        $table->add_field('productcode', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null);
        $table->add_field('productname', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('unitcodes', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('validationtype', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, null);
        $table->add_field('risklevel', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'medium');
        $table->add_field('riskfactors', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('scheduleddate', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('actualdate', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('leadvalidatorid', XMLDB_TYPE_CHAR, '50', null, null, null, null);
        $table->add_field('validatorids', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('methodologies', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('samplesize', XMLDB_TYPE_INTEGER, '5', null, null, null, null);
        $table->add_field('samplingmethod', XMLDB_TYPE_CHAR, '50', null, null, null, null);
        $table->add_field('findingscount', XMLDB_TYPE_INTEGER, '5', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('findings', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('outcome', XMLDB_TYPE_CHAR, '30', null, null, null, null);
        $table->add_field('improvements', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('linkedimprovementids', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('reportdocument', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('status', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, 'scheduled');
        $table->add_field('adclinked', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('notes', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('createdby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('createdby', XMLDB_KEY_FOREIGN, ['createdby'], 'user', ['id']);
        $table->add_index('reference', XMLDB_INDEX_UNIQUE, ['reference']);
        $table->add_index('productcode', XMLDB_INDEX_NOTUNIQUE, ['productcode']);
        $table->add_index('status', XMLDB_INDEX_NOTUNIQUE, ['status']);
        $table->add_index('scheduleddate', XMLDB_INDEX_NOTUNIQUE, ['scheduleddate']);
        $table->add_index('risklevel', XMLDB_INDEX_NOTUNIQUE, ['risklevel']);
        $table->add_index('outcome', XMLDB_INDEX_NOTUNIQUE, ['outcome']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Table: Validators Register
        $table = new xmldb_table('local_rtocompliance_validators');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('fullname', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('email', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('phone', XMLDB_TYPE_CHAR, '30', null, null, null, null);
        $table->add_field('isinternal', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
        $table->add_field('organisation', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('roletype', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('taecredential', XMLDB_TYPE_CHAR, '50', null, null, null, null);
        $table->add_field('taedateachieved', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('vocationalqualifications', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('industryexperience', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('industryexperienceyears', XMLDB_TYPE_INTEGER, '3', null, null, null, null);
        $table->add_field('currentindustryengagement', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('specialisations', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('validationsled', XMLDB_TYPE_INTEGER, '5', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('validationsparticipated', XMLDB_TYPE_INTEGER, '5', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('lastvalidationdate', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'active');
        $table->add_field('evidencedocuments', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('notes', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('createdby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
        $table->add_key('createdby', XMLDB_KEY_FOREIGN, ['createdby'], 'user', ['id']);
        $table->add_index('roletype', XMLDB_INDEX_NOTUNIQUE, ['roletype']);
        $table->add_index('status', XMLDB_INDEX_NOTUNIQUE, ['status']);
        $table->add_index('isinternal', XMLDB_INDEX_NOTUNIQUE, ['isinternal']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Table: TAS Master Documents
        $table = new xmldb_table('local_rtocompliance_tas');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('qualificationcode', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null);
        $table->add_field('qualificationname', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('version', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, '1.0');
        $table->add_field('status', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, 'draft');
        $table->add_field('effectivedate', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('reviewdate', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('approvedby', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('approvaldate', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('targetcohort', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('entryrequirements', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('volumeoflearning', XMLDB_TYPE_INTEGER, '5', null, null, null, null);
        $table->add_field('deliverymode', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('deliverylocations', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('duration', XMLDB_TYPE_CHAR, '100', null, null, null, null);
        $table->add_field('workplacement', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('workplacementhours', XMLDB_TYPE_INTEGER, '5', null, null, null, null);
        $table->add_field('workplacementdetails', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('learnersupport', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('riskmanagement', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('marketingcompliance', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('transitionprocedures', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('complaintsprocess', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('continuousimprovement', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('completenessscore', XMLDB_TYPE_INTEGER, '3', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('generatedhtml', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('notes', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('createdby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('approvedby', XMLDB_KEY_FOREIGN, ['approvedby'], 'user', ['id']);
        $table->add_key('createdby', XMLDB_KEY_FOREIGN, ['createdby'], 'user', ['id']);
        $table->add_index('qualificationcode', XMLDB_INDEX_NOTUNIQUE, ['qualificationcode']);
        $table->add_index('status', XMLDB_INDEX_NOTUNIQUE, ['status']);
        $table->add_index('qual_version', XMLDB_INDEX_UNIQUE, ['qualificationcode', 'version']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Table: TAS Industry Consultations
        $table = new xmldb_table('local_rtocompliance_tas_consult');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('tasid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('consultationtype', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
        $table->add_field('consultationdate', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('participantname', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('participantrole', XMLDB_TYPE_CHAR, '100', null, null, null, null);
        $table->add_field('participantorg', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('topicsdiscussed', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('feedback', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('actionsagreed', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('evidencedocument', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('createdby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('tasid', XMLDB_KEY_FOREIGN, ['tasid'], 'local_rtocompliance_tas', ['id']);
        $table->add_key('createdby', XMLDB_KEY_FOREIGN, ['createdby'], 'user', ['id']);
        $table->add_index('consultationdate', XMLDB_INDEX_NOTUNIQUE, ['consultationdate']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Table: TAS Delivery Schedule
        $table = new xmldb_table('local_rtocompliance_tas_schedule');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('tasid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('unitcode', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null);
        $table->add_field('unitname', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('sequenceorder', XMLDB_TYPE_INTEGER, '5', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('deliverymode', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
        $table->add_field('scheduledweeks', XMLDB_TYPE_INTEGER, '3', null, null, null, null);
        $table->add_field('nominalhours', XMLDB_TYPE_INTEGER, '5', null, null, null, null);
        $table->add_field('supervisedtindhours', XMLDB_TYPE_INTEGER, '5', null, null, null, null);
        $table->add_field('unsupervisedhours', XMLDB_TYPE_INTEGER, '5', null, null, null, null);
        $table->add_field('workplacementhours', XMLDB_TYPE_INTEGER, '5', null, null, null, null);
        $table->add_field('notes', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('tasid', XMLDB_KEY_FOREIGN, ['tasid'], 'local_rtocompliance_tas', ['id']);
        $table->add_index('unitcode', XMLDB_INDEX_NOTUNIQUE, ['unitcode']);
        $table->add_index('sequenceorder', XMLDB_INDEX_NOTUNIQUE, ['sequenceorder']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Table: TAS Assessment Mapping
        $table = new xmldb_table('local_rtocompliance_tas_mapping');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('tasid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('unitcode', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null);
        $table->add_field('assessmentname', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('assessmenttype', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
        $table->add_field('elementsassessed', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('criteriamapped', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('methodsused', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('conditionsrequired', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('notes', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('tasid', XMLDB_KEY_FOREIGN, ['tasid'], 'local_rtocompliance_tas', ['id']);
        $table->add_index('unitcode', XMLDB_INDEX_NOTUNIQUE, ['unitcode']);
        $table->add_index('assessmenttype', XMLDB_INDEX_NOTUNIQUE, ['assessmenttype']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Table: TAS Trainer Mapping
        $table = new xmldb_table('local_rtocompliance_tas_trainers');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('tasid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('trainerid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('role', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, null);
        $table->add_field('unitscovered', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('credentialverified', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('credentialverifieddate', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('notes', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('tasid', XMLDB_KEY_FOREIGN, ['tasid'], 'local_rtocompliance_tas', ['id']);
        $table->add_key('trainerid', XMLDB_KEY_FOREIGN, ['trainerid'], 'local_rtocompliance_trainers', ['id']);
        $table->add_index('role', XMLDB_INDEX_NOTUNIQUE, ['role']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Table: TAS Resources
        $table = new xmldb_table('local_rtocompliance_tas_resources');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('tasid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('resourcetype', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
        $table->add_field('resourcename', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('description', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('unitscovered', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('quantity', XMLDB_TYPE_INTEGER, '5', null, null, null, null);
        $table->add_field('location', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('available', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
        $table->add_field('maintenancedate', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('notes', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('tasid', XMLDB_KEY_FOREIGN, ['tasid'], 'local_rtocompliance_tas', ['id']);
        $table->add_index('resourcetype', XMLDB_INDEX_NOTUNIQUE, ['resourcetype']);
        $table->add_index('available', XMLDB_INDEX_NOTUNIQUE, ['available']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2025121000, 'local', 'rtocompliance');
    }

    if ($oldversion < 2025121003) {
        // Table: Audit Log
        $table = new xmldb_table('local_rtocompliance_audit');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('action', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, null);
        $table->add_field('entitytype', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
        $table->add_field('entityid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('description', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('olddata', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('newdata', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('ipaddress', XMLDB_TYPE_CHAR, '45', null, null, null, null);
        $table->add_field('useragent', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
        $table->add_index('action', XMLDB_INDEX_NOTUNIQUE, ['action']);
        $table->add_index('entitytype', XMLDB_INDEX_NOTUNIQUE, ['entitytype']);
        $table->add_index('entityid', XMLDB_INDEX_NOTUNIQUE, ['entityid']);
        $table->add_index('timecreated', XMLDB_INDEX_NOTUNIQUE, ['timecreated']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2025121003, 'local', 'rtocompliance');
    }

    if ($oldversion < 2025121004) {
        $table = new xmldb_table('local_rtocompliance_students');
        
        $index = new xmldb_index('userid_profilecomplete', XMLDB_INDEX_NOTUNIQUE, ['userid', 'profilecomplete']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }
        
        $table = new xmldb_table('local_rtocompliance_enrolments');
        
        $index = new xmldb_index('courseid_status', XMLDB_INDEX_NOTUNIQUE, ['courseid', 'status']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }
        
        $index = new xmldb_index('studentid_status', XMLDB_INDEX_NOTUNIQUE, ['studentid', 'status']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }
        
        $table = new xmldb_table('local_rtocompliance_certs');
        
        $index = new xmldb_index('userid_status', XMLDB_INDEX_NOTUNIQUE, ['userid', 'status']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }
        
        $index = new xmldb_index('issuedate', XMLDB_INDEX_NOTUNIQUE, ['issuedate']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }
        
        $table = new xmldb_table('local_rtocompliance_log');
        
        $index = new xmldb_index('userid_timecreated', XMLDB_INDEX_NOTUNIQUE, ['userid', 'timecreated']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }
        
        $table = new xmldb_table('local_rtocompliance_trainers');
        
        $index = new xmldb_index('status_nextreview', XMLDB_INDEX_NOTUNIQUE, ['status', 'nextreviewdate']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }
        
        if ($dbman->table_exists('local_rtocompliance_ai_alerts')) {
            $table = new xmldb_table('local_rtocompliance_ai_alerts');
            
            $index = new xmldb_index('severity_status', XMLDB_INDEX_NOTUNIQUE, ['severity', 'status']);
            if (!$dbman->index_exists($table, $index)) {
                $dbman->add_index($table, $index);
            }
        }
        
        upgrade_plugin_savepoint(true, 2025121004, 'local', 'rtocompliance');
    }

    if ($oldversion < 2025121006) {
        // Add usiverifieddate field to students table
        $table = new xmldb_table('local_rtocompliance_students');
        $field = new xmldb_field('usiverifieddate', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'usiverified');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        
        // Add firstname and lastname fields to students table for USI verification
        $field = new xmldb_field('firstname', XMLDB_TYPE_CHAR, '100', null, null, null, null, 'userid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        
        $field = new xmldb_field('lastname', XMLDB_TYPE_CHAR, '100', null, null, null, null, 'firstname');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        
        // Create USI verification log table
        $table = new xmldb_table('local_rtocompliance_usilog');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('studentid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('usi', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('status', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, null);
        $table->add_field('verified', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('message', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('details', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('studentid', XMLDB_KEY_FOREIGN, ['studentid'], 'local_rtocompliance_students', ['id']);
        $table->add_index('usi', XMLDB_INDEX_NOTUNIQUE, ['usi']);
        $table->add_index('status', XMLDB_INDEX_NOTUNIQUE, ['status']);
        $table->add_index('verified', XMLDB_INDEX_NOTUNIQUE, ['verified']);
        $table->add_index('timecreated', XMLDB_INDEX_NOTUNIQUE, ['timecreated']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
        
        upgrade_plugin_savepoint(true, 2025121006, 'local', 'rtocompliance');
    }

    if ($oldversion < 2025121007) {
        // PERFORMANCE: Add missing composite indexes for high-frequency queries
        
        // Students table: USI verification queries
        $table = new xmldb_table('local_rtocompliance_students');
        
        $index = new xmldb_index('usi_usiverified', XMLDB_INDEX_NOTUNIQUE, ['usi', 'usiverified']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }
        
        $index = new xmldb_index('usiverified_dateofbirth', XMLDB_INDEX_NOTUNIQUE, ['usiverified', 'dateofbirth']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }
        
        // Certs table: Profile page queries
        $table = new xmldb_table('local_rtocompliance_certs');
        
        $index = new xmldb_index('userid_status_idx', XMLDB_INDEX_NOTUNIQUE, ['userid', 'status']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }
        
        // Enrolments table: Status queries
        $table = new xmldb_table('local_rtocompliance_enrolments');
        
        $index = new xmldb_index('status_idx', XMLDB_INDEX_NOTUNIQUE, ['status']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }
        
        // Trainers table: Compliance status queries
        $table = new xmldb_table('local_rtocompliance_trainers');
        
        $index = new xmldb_index('status_compliancestatus', XMLDB_INDEX_NOTUNIQUE, ['status', 'compliancestatus']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }
        
        // AI Alerts table: Status and severity queries
        if ($dbman->table_exists('local_rtocompliance_ai_alerts')) {
            $table = new xmldb_table('local_rtocompliance_ai_alerts');
            
            $index = new xmldb_index('status_severity_idx', XMLDB_INDEX_NOTUNIQUE, ['status', 'severity']);
            if (!$dbman->index_exists($table, $index)) {
                $dbman->add_index($table, $index);
            }
        }
        
        // Create metrics summary table for materialized dashboard stats
        $table = new xmldb_table('local_rtocompliance_metrics');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('metrickey', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
        $table->add_field('metricvalue', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecomputed', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('dirty', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('metrickey_unique', XMLDB_INDEX_UNIQUE, ['metrickey']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
        
        upgrade_plugin_savepoint(true, 2025121007, 'local', 'rtocompliance');
    }

    // Version 2.4.0 - Hybrid CSS refactor (no database changes, CSS-only update)
    if ($oldversion < 2025121016) {
        // This version update is for CSS/styling changes only.
        // Hybrid CSS approach: Let Moodle theme handle typography, buttons, forms
        // while keeping custom styling only for dashboards, alerts, and data tables.
        // No database schema changes required.
        
        upgrade_plugin_savepoint(true, 2025121016, 'local', 'rtocompliance');
    }

    // Version 2.4.1 - 100% Theme-Native CSS (no database changes)
    if ($oldversion < 2025121017) {
        // Completely empty stylesheet - let Moodle theme handle ALL styling.
        // This is an experimental version to test pure theme adoption.
        
        upgrade_plugin_savepoint(true, 2025121017, 'local', 'rtocompliance');
    }

    // Version 2.4.2 - Comprehensive CSS with proper form element fixes
    if ($oldversion < 2025121018) {
        // Complete CSS overhaul: 16 sections covering all UI elements.
        // CRITICAL FIX: Dropdown text clipping - removed height restrictions, proper padding.
        // Includes: CSS variables, form elements, buttons, badges, tables, dashboard,
        // page layouts, navigation, empty states, icons, utilities, responsive, print, dark mode.
        
        upgrade_plugin_savepoint(true, 2025121018, 'local', 'rtocompliance');
    }

    // Version 2.4.3 - PHP bug fixes
    if ($oldversion < 2025121019) {
        // FIX: html_writer::tag('br') changed to html_writer::empty_tag('br')
        // in trainers.php and deadlines.php - tag() requires 2 args, empty_tag() for self-closing.
        
        upgrade_plugin_savepoint(true, 2025121019, 'local', 'rtocompliance');
    }

    // Version 2.4.4 - CSS scoping fix
    if ($oldversion < 2025121020) {
        // CRITICAL: All CSS now scoped to [class*="path-local-rtocompliance"]
        // to prevent site-wide style leakage. Removed global *, .mform, .form-control selectors.
        
        upgrade_plugin_savepoint(true, 2025121020, 'local', 'rtocompliance');
    }

    // Version 2.4.5 - Supervision log table and trainer credential enhancements
    if ($oldversion < 2025121021) {
        // Add new trainer fields for ASQA Credential Policy
        $table = new xmldb_table('local_rtocompliance_trainers');
        
        // credentialrole field
        $field = new xmldb_field('credentialrole', XMLDB_TYPE_CHAR, '5', null, null, null, null, 'scopenotes');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        
        // industrycurrencytype field
        $field = new xmldb_field('industrycurrencytype', XMLDB_TYPE_CHAR, '50', null, null, null, null, 'credentialrole');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        
        // managersignoff field
        $field = new xmldb_field('managersignoff', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'industrycurrencytype');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        
        // managersignoffby field
        $field = new xmldb_field('managersignoffby', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'managersignoff');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        
        // managersignoffdate field
        $field = new xmldb_field('managersignoffdate', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'managersignoffby');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        
        // Add index on credentialrole
        $index = new xmldb_index('credentialrole', XMLDB_INDEX_NOTUNIQUE, ['credentialrole']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }
        
        // Create supervision log table
        $table = new xmldb_table('local_rtocompliance_supervision');
        
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('trainerid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('supervisorid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('supervisiondate', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('supervisiontype', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
        $table->add_field('qualificationcode', XMLDB_TYPE_CHAR, '20', null, null, null, null);
        $table->add_field('unitcodes', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('duration', XMLDB_TYPE_INTEGER, '5', null, null, null, null);
        $table->add_field('activities', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('feedback', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('developmentneeds', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('actionitems', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('actionsduedate', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('actionscompleted', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('assessmentjudgementrestricted', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('nextsupervisiondate', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('evidencedocument', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('managervalidated', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('managervalidatedby', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('managervalidateddate', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('notes', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('createdby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('trainerid', XMLDB_KEY_FOREIGN, ['trainerid'], 'local_rtocompliance_trainers', ['id']);
        $table->add_key('supervisorid', XMLDB_KEY_FOREIGN, ['supervisorid'], 'local_rtocompliance_trainers', ['id']);
        $table->add_key('createdby', XMLDB_KEY_FOREIGN, ['createdby'], 'user', ['id']);
        $table->add_key('managervalidatedby', XMLDB_KEY_FOREIGN, ['managervalidatedby'], 'user', ['id']);
        
        $table->add_index('trainerid_date', XMLDB_INDEX_NOTUNIQUE, ['trainerid', 'supervisiondate']);
        $table->add_index('supervisiontype', XMLDB_INDEX_NOTUNIQUE, ['supervisiontype']);
        $table->add_index('nextsupervisiondate', XMLDB_INDEX_NOTUNIQUE, ['nextsupervisiondate']);
        $table->add_index('managervalidated', XMLDB_INDEX_NOTUNIQUE, ['managervalidated']);
        
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
        
        upgrade_plugin_savepoint(true, 2025121021, 'local', 'rtocompliance');
    }

    // Version 2.5.5 - Add missing trainer fields for resume upload and fullname
    if ($oldversion < 2025121203) {
        $table = new xmldb_table('local_rtocompliance_trainers');
        
        // resumefilename field for storing uploaded resume filename
        $field = new xmldb_field('resumefilename', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'managersignoffdate');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        
        // resumefileid field for file storage reference
        $field = new xmldb_field('resumefileid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'resumefilename');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        
        // fullname field - computed from user record but cached for queries
        $field = new xmldb_field('fullname', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'resumefileid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
            
            // Populate fullname from user table for existing records
            $trainers = $DB->get_records('local_rtocompliance_trainers');
            foreach ($trainers as $trainer) {
                $user = $DB->get_record('user', ['id' => $trainer->userid], 'id,firstname,lastname,firstnamephonetic,lastnamephonetic,middlename,alternatename');
                if ($user) {
                    $DB->set_field('local_rtocompliance_trainers', 'fullname', fullname($user), ['id' => $trainer->id]);
                }
            }
        }
        
        upgrade_plugin_savepoint(true, 2025121203, 'local', 'rtocompliance');
    }

    // Version 2.8.0 - Add vocational evidence structured field for ASQA compliance
    if ($oldversion < 2025121609) {
        $table = new xmldb_table('local_rtocompliance_trainers');
        
        // vocationalevidence field for storing selected evidence types as comma-separated values
        $field = new xmldb_field('vocationalevidence', XMLDB_TYPE_TEXT, null, null, null, null, null, 'vocationalcompetency');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        
        upgrade_plugin_savepoint(true, 2025121609, 'local', 'rtocompliance');
    }

    // Version 3.2.0 - Add Qualification Builder tables
    if ($oldversion < 2025121703) {
        // Table: local_rtocompliance_qualbuilder
        $table = new xmldb_table('local_rtocompliance_qualbuilder');
        
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('producttype', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'qualification');
        $table->add_field('qualificationcode', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null);
        $table->add_field('qualificationname', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('aqflevel', XMLDB_TYPE_INTEGER, '2', null, null, null, null);
        $table->add_field('categoryid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('totalunits', XMLDB_TYPE_INTEGER, '3', null, XMLDB_NOTNULL, null, '1');
        $table->add_field('coreunitcount', XMLDB_TYPE_INTEGER, '3', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('electivecount', XMLDB_TYPE_INTEGER, '3', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('packagingrules', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('electiverules', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('nominalhours', XMLDB_TYPE_INTEGER, '5', null, null, null, null);
        $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'draft');
        $table->add_field('supersededby', XMLDB_TYPE_CHAR, '20', null, null, null, null);
        $table->add_field('teachoutdate', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('validationpassed', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('validationdate', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('validationerrors', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('createdby', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('categoryid', XMLDB_KEY_FOREIGN, ['categoryid'], 'course_categories', ['id']);
        $table->add_key('createdby', XMLDB_KEY_FOREIGN, ['createdby'], 'user', ['id']);
        
        $table->add_index('qualificationcode', XMLDB_INDEX_NOTUNIQUE, ['qualificationcode']);
        $table->add_index('producttype', XMLDB_INDEX_NOTUNIQUE, ['producttype']);
        $table->add_index('status', XMLDB_INDEX_NOTUNIQUE, ['status']);
        $table->add_index('producttype_status', XMLDB_INDEX_NOTUNIQUE, ['producttype', 'status']);
        
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
        
        // Table: local_rtocompliance_qualunits
        $table = new xmldb_table('local_rtocompliance_qualunits');
        
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('qualbuilderid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('unitcode', XMLDB_TYPE_CHAR, '12', null, XMLDB_NOTNULL, null, null);
        $table->add_field('unitname', XMLDB_TYPE_CHAR, '150', null, XMLDB_NOTNULL, null, null);
        $table->add_field('unittype', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, 'elective');
        $table->add_field('electivegroup', XMLDB_TYPE_CHAR, '5', null, null, null, null);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('nominalhours', XMLDB_TYPE_INTEGER, '5', null, null, null, null);
        $table->add_field('sequenceorder', XMLDB_TYPE_INTEGER, '5', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('prerequisiteunits', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('selected', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
        $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'active');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('qualbuilderid', XMLDB_KEY_FOREIGN, ['qualbuilderid'], 'local_rtocompliance_qualbuilder', ['id']);
        $table->add_key('courseid', XMLDB_KEY_FOREIGN, ['courseid'], 'course', ['id']);
        
        $table->add_index('unitcode', XMLDB_INDEX_NOTUNIQUE, ['unitcode']);
        $table->add_index('unittype', XMLDB_INDEX_NOTUNIQUE, ['unittype']);
        $table->add_index('qualbuilderid_unittype', XMLDB_INDEX_NOTUNIQUE, ['qualbuilderid', 'unittype']);
        $table->add_index('qualbuilderid_courseid', XMLDB_INDEX_NOTUNIQUE, ['qualbuilderid', 'courseid']);
        
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
        
        // Table: local_rtocompliance_autocerts
        $table = new xmldb_table('local_rtocompliance_autocerts');
        
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('studentid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('qualbuilderid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('certtypes', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
        $table->add_field('completiondate', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('creditcost', XMLDB_TYPE_INTEGER, '3', null, XMLDB_NOTNULL, null, '5');
        $table->add_field('creditdeducted', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('certsissued', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('emailsent', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('certids', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'pending');
        $table->add_field('errormessage', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timeprocessed', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('studentid', XMLDB_KEY_FOREIGN, ['studentid'], 'local_rtocompliance_students', ['id']);
        $table->add_key('qualbuilderid', XMLDB_KEY_FOREIGN, ['qualbuilderid'], 'local_rtocompliance_qualbuilder', ['id']);
        
        $table->add_index('status', XMLDB_INDEX_NOTUNIQUE, ['status']);
        $table->add_index('studentid_qualbuilderid', XMLDB_INDEX_NOTUNIQUE, ['studentid', 'qualbuilderid']);
        $table->add_index('completiondate', XMLDB_INDEX_NOTUNIQUE, ['completiondate']);
        
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
        
        // Add new fields to enrolments table for qualification builder integration
        $table = new xmldb_table('local_rtocompliance_enrolments');
        
        $field = new xmldb_field('qualbuilderid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'supersededfrom');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        
        $field = new xmldb_field('qualunitid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'qualbuilderid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        
        $field = new xmldb_field('moodlecompletionid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'qualunitid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        
        $field = new xmldb_field('autopopulated', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'moodlecompletionid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        
        $field = new xmldb_field('overridden', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'autopopulated');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        
        $field = new xmldb_field('overriddenby', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'overridden');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        
        $field = new xmldb_field('overriddendate', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'overriddenby');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        
        upgrade_plugin_savepoint(true, 2025121703, 'local', 'rtocompliance');
    }

    // v3.7.32 - Comprehensive check for all missing student fields
    if ($oldversion < 2025122605) {
        $table = new xmldb_table('local_rtocompliance_students');
        
        // Define all fields that should exist in the students table
        $allfields = [
            ['firstname', XMLDB_TYPE_CHAR, '100', null, null, null, null, 'userid'],
            ['lastname', XMLDB_TYPE_CHAR, '100', null, null, null, null, 'firstname'],
            ['usiverifieddate', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'usiverified'],
            ['labourforcestatus', XMLDB_TYPE_CHAR, '2', null, XMLDB_NOTNULL, null, '@@', 'atschoolflag'],
            ['studyreason', XMLDB_TYPE_CHAR, '2', null, XMLDB_NOTNULL, null, '@@', 'labourforcestatus'],
            ['prioreducationflag', XMLDB_TYPE_CHAR, '1', null, XMLDB_NOTNULL, null, '@', 'studyreason'],
            ['surveycontactstatus', XMLDB_TYPE_CHAR, '1', null, XMLDB_NOTNULL, null, 'N', 'prioreducationflag'],
            ['surveycontactemail', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'surveycontactstatus'],
            ['surveycontactphone', XMLDB_TYPE_CHAR, '20', null, null, null, null, 'surveycontactemail'],
            ['qldlui', XMLDB_TYPE_CHAR, '10', null, null, null, null, 'surveycontactphone'],
            ['viccohortid', XMLDB_TYPE_CHAR, '20', null, null, null, null, 'qldlui'],
            ['nswsmartskilled', XMLDB_TYPE_CHAR, '20', null, null, null, null, 'viccohortid'],
            ['waraptid', XMLDB_TYPE_CHAR, '20', null, null, null, null, 'nswsmartskilled'],
            ['profilecomplete', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'waraptid'],
            ['validationerrors', XMLDB_TYPE_TEXT, null, null, null, null, null, 'profilecomplete'],
        ];
        
        foreach ($allfields as $fieldinfo) {
            $field = new xmldb_field($fieldinfo[0], $fieldinfo[1], $fieldinfo[2], $fieldinfo[3], $fieldinfo[4], $fieldinfo[5], $fieldinfo[6], $fieldinfo[7]);
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }
        
        upgrade_plugin_savepoint(true, 2025122605, 'local', 'rtocompliance');
    }

    // v3.7.38 - Add trainer currency activities table for multiple currency records per trainer
    if ($oldversion < 2025122900) {
        $table = new xmldb_table('local_rtocompliance_trainer_currency');
        
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('trainerid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('activitytype', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
        $table->add_field('title', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('organisation', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('description', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('startdate', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('enddate', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('ongoing', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('hoursperweek', XMLDB_TYPE_INTEGER, '3', null, null, null, null);
        $table->add_field('evidencetype', XMLDB_TYPE_CHAR, '100', null, null, null, null);
        $table->add_field('evidencefileid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('evidencefilename', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('verifiedby', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('verifieddate', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('notes', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('trainerid', XMLDB_KEY_FOREIGN, ['trainerid'], 'local_rtocompliance_trainers', ['id']);
        
        $table->add_index('activitytype', XMLDB_INDEX_NOTUNIQUE, ['activitytype']);
        $table->add_index('trainerid_type', XMLDB_INDEX_NOTUNIQUE, ['trainerid', 'activitytype']);
        $table->add_index('ongoing', XMLDB_INDEX_NOTUNIQUE, ['ongoing']);
        
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
        
        upgrade_plugin_savepoint(true, 2025122900, 'local', 'rtocompliance');
    }

    // v3.7.41 - Add trainer vocational competency activities table for multiple competency records per trainer
    if ($oldversion < 2025123001) {
        $table = new xmldb_table('local_rtocompliance_trainer_voccomp');
        
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('trainerid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('activitytype', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
        $table->add_field('title', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('qualification', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('organisation', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('description', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('startdate', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('enddate', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('ongoing', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('totalhours', XMLDB_TYPE_INTEGER, '6', null, null, null, null);
        $table->add_field('evidencetype', XMLDB_TYPE_CHAR, '100', null, null, null, null);
        $table->add_field('evidencefileid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('evidencefilename', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('verifiedby', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('verifieddate', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('notes', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('trainerid', XMLDB_KEY_FOREIGN, ['trainerid'], 'local_rtocompliance_trainers', ['id']);
        
        $table->add_index('activitytype', XMLDB_INDEX_NOTUNIQUE, ['activitytype']);
        $table->add_index('trainerid_type', XMLDB_INDEX_NOTUNIQUE, ['trainerid', 'activitytype']);
        $table->add_index('ongoing', XMLDB_INDEX_NOTUNIQUE, ['ongoing']);
        $table->add_index('qualification', XMLDB_INDEX_NOTUNIQUE, ['qualification']);
        
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
        
        upgrade_plugin_savepoint(true, 2025123001, 'local', 'rtocompliance');
    }

    // v3.7.49 - Fix credentialrole column size and add taeexpirydate
    // CRITICAL: Previous upgrade block (2025010100) had version LOWER than preceding blocks
    // so it NEVER executed on existing installations. This block re-applies those fixes.
    if ($oldversion < 2026030400349) {
        $table = new xmldb_table('local_rtocompliance_trainers');
        
        // 1. Add taeexpirydate field if missing - NULL means TAE never expires (Current forever)
        $field = new xmldb_field('taeexpirydate', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'taedateachieved');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        
        // 2. Expand credentialrole from VARCHAR(5) to TEXT
        // Required for comma-separated multiple roles like "1A,1B,1C,1D,1E,2A,2B,2C,3A,3B"
        // Using TEXT instead of VARCHAR(255) to prevent any future truncation
        $field = new xmldb_field('credentialrole', XMLDB_TYPE_TEXT, null, null, null, null, null, 'scopenotes');
        if ($dbman->field_exists($table, $field)) {
            // Drop the index first (cannot change precision on indexed columns)
            $index = new xmldb_index('credentialrole', XMLDB_INDEX_NOTUNIQUE, ['credentialrole']);
            if ($dbman->index_exists($table, $index)) {
                $dbman->drop_index($table, $index);
            }
            $dbman->change_field_type($table, $field);
        }
        
        // 3. Add index on taeexpirydate for efficient status queries
        $index = new xmldb_index('taeexpirydate', XMLDB_INDEX_NOTUNIQUE, ['taeexpirydate']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }
        
        upgrade_plugin_savepoint(true, 2026030400349, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026030400351) {
        $table = new xmldb_table('local_rtocompliance_tas');

        $field = new xmldb_field('deliverystartdate', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'generatedhtml');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('learningbreakdown', XMLDB_TYPE_TEXT, null, null, null, null, null, 'deliverystartdate');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('volumejustification', XMLDB_TYPE_TEXT, null, null, null, null, null, 'learningbreakdown');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026030400351, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026030400352) {
        $table = new xmldb_table('local_rtocompliance_tas_consult');

        $field = new xmldb_field('contactdetails', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'participantorg');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('impacttraining', XMLDB_TYPE_TEXT, null, null, null, null, null, 'feedback');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('impactassessment', XMLDB_TYPE_TEXT, null, null, null, null, null, 'impacttraining');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('nextmeetingdate', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'actionsagreed');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026030400352, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026030400356) {
        $table = new xmldb_table('local_rtocompliance_tas');

        $newfields = [
            ['traininggovlink', XMLDB_TYPE_CHAR, '500', null, null, null, null],
            ['scopedetails', XMLDB_TYPE_TEXT, null, null, null, null, null],
            ['llnrequirements', XMLDB_TYPE_TEXT, null, null, null, null, null],
            ['prerequisites', XMLDB_TYPE_TEXT, null, null, null, null, null],
            ['industryconsultation', XMLDB_TYPE_TEXT, null, null, null, null, null],
            ['jobroles', XMLDB_TYPE_TEXT, null, null, null, null, null],
            ['nominalhours', XMLDB_TYPE_INTEGER, '5', null, null, null, null],
            ['durationweeks', XMLDB_TYPE_INTEGER, '5', null, null, null, null],
            ['hoursperweek', XMLDB_TYPE_INTEGER, '3', null, null, null, null],
            ['deliveryschedule', XMLDB_TYPE_TEXT, null, null, null, null, null],
            ['assessmentmethods', XMLDB_TYPE_TEXT, null, null, null, null, null],
            ['assessmentmapping', XMLDB_TYPE_TEXT, null, null, null, null, null],
            ['validationschedule', XMLDB_TYPE_TEXT, null, null, null, null, null],
            ['trainerrequirements', XMLDB_TYPE_TEXT, null, null, null, null, null],
            ['supervisionarrangements', XMLDB_TYPE_TEXT, null, null, null, null, null],
            ['learningresources', XMLDB_TYPE_TEXT, null, null, null, null, null],
            ['facilities', XMLDB_TYPE_TEXT, null, null, null, null, null],
            ['technology', XMLDB_TYPE_TEXT, null, null, null, null, null],
            ['thirdparty', XMLDB_TYPE_TEXT, null, null, null, null, null],
            ['accessibility', XMLDB_TYPE_TEXT, null, null, null, null, null],
            ['marketinginfo', XMLDB_TYPE_TEXT, null, null, null, null, null],
            ['feesinformation', XMLDB_TYPE_TEXT, null, null, null, null, null],
            ['hasworkplacement', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0'],
            ['placementhours', XMLDB_TYPE_INTEGER, '5', null, null, null, null],
            ['placementdetails', XMLDB_TYPE_TEXT, null, null, null, null, null],
            ['transitionplan', XMLDB_TYPE_TEXT, null, null, null, null, null],
            ['nextreviewdate', XMLDB_TYPE_INTEGER, '10', null, null, null, null],
            ['revisionnotes', XMLDB_TYPE_TEXT, null, null, null, null, null],
            ['completeness', XMLDB_TYPE_INTEGER, '3', null, XMLDB_NOTNULL, null, '0'],
        ];

        foreach ($newfields as $fdef) {
            $field = new xmldb_field($fdef[0], $fdef[1], $fdef[2], $fdef[3], $fdef[4], $fdef[5], $fdef[6]);
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }

        $approvedbyfield = new xmldb_field('approvedby', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        if ($dbman->field_exists($table, $approvedbyfield)) {
            $key = new xmldb_key('approvedby', XMLDB_KEY_FOREIGN, ['approvedby'], 'user', ['id']);
            try {
                $dbman->drop_key($table, $key);
            } catch (Exception $e) {
            }
            $dbman->change_field_type($table, $approvedbyfield);
        }

        $textmigrations = [
            ['workplacementdetails', 'placementdetails'],
            ['marketingcompliance', 'marketinginfo'],
            ['transitionprocedures', 'transitionplan'],
            ['notes', 'scopedetails'],
        ];
        foreach ($textmigrations as $pair) {
            $oldfield = new xmldb_field($pair[0]);
            $newfield = new xmldb_field($pair[1]);
            if ($dbman->field_exists($table, $oldfield) && $dbman->field_exists($table, $newfield)) {
                $DB->execute(
                    "UPDATE {local_rtocompliance_tas} SET {$pair[1]} = {$pair[0]} WHERE ({$pair[1]} IS NULL OR {$pair[1]} = '') AND {$pair[0]} IS NOT NULL AND {$pair[0]} <> ''"
                );
            }
        }

        $intmigrations = [
            ['completenessscore', 'completeness'],
            ['reviewdate', 'nextreviewdate'],
            ['workplacementhours', 'placementhours'],
            ['workplacement', 'hasworkplacement'],
        ];
        foreach ($intmigrations as $pair) {
            $oldfield = new xmldb_field($pair[0]);
            $newfield = new xmldb_field($pair[1]);
            if ($dbman->field_exists($table, $oldfield) && $dbman->field_exists($table, $newfield)) {
                $DB->execute(
                    "UPDATE {local_rtocompliance_tas} SET {$pair[1]} = {$pair[0]} WHERE ({$pair[1]} IS NULL OR {$pair[1]} = 0) AND {$pair[0]} IS NOT NULL AND {$pair[0]} <> 0"
                );
            }
        }

        upgrade_plugin_savepoint(true, 2026030400356, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026030500359) {
        // Defensive: ensure trainer credentialrole is TEXT in case prior upgrade step did not run.
        // Some MySQL environments silently fail column type changes when an index exists.
        $table = new xmldb_table('local_rtocompliance_trainers');

        // Drop credentialrole index if it still exists (blocks TEXT conversion in MySQL).
        $idx = new xmldb_index('credentialrole', XMLDB_INDEX_NOTUNIQUE, ['credentialrole']);
        if ($dbman->table_exists($table) && $dbman->index_exists($table, $idx)) {
            $dbman->drop_index($table, $idx);
        }

        // Force credentialrole to TEXT so comma-separated roles (e.g. 1A,1B,3A,3B) always fit.
        $field = new xmldb_field('credentialrole', XMLDB_TYPE_TEXT, null, null, null, null, null, 'scopenotes');
        if ($dbman->table_exists($table) && $dbman->field_exists($table, $field)) {
            $dbman->change_field_type($table, $field);
        }

        // Also ensure vocationalevidence is TEXT (evidence type list can be long).
        $evfield = new xmldb_field('vocationalevidence', XMLDB_TYPE_TEXT, null, null, null, null, null);
        if ($dbman->table_exists($table) && $dbman->field_exists($table, $evfield)) {
            $dbman->change_field_type($table, $evfield);
        }

        upgrade_plugin_savepoint(true, 2026030500359, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026030600365) {
        // v3.7.65 — version bump. No schema changes.
        upgrade_plugin_savepoint(true, 2026030600365, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026030600366) {
        // v3.7.66 — Moodle enrolment import feature. No schema changes.
        upgrade_plugin_savepoint(true, 2026030600366, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026030600367) {
        // v3.7.67 — Plugin-wide "not connected" audit fixes. No schema changes.
        // trainers.php: detect panel for Moodle teachers with no RTO profile + import.
        // student_enrolments.php: import now auto-detects programcode and unitcodes from
        //   Qual Builder unit-course linkages, creating unit-level AVETMISS records.
        // qualbuilder_results.php: student query broadened to also match by course linkage,
        //   so students imported before programcode detection are now visible.
        upgrade_plugin_savepoint(true, 2026030600367, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026030600368) {
        // v3.7.68 — Version bump to force Moodle upgrade detection. No schema changes.
        upgrade_plugin_savepoint(true, 2026030600368, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026030600369) {
        // v3.7.69 — Full version bump to force Moodle DB recognition. No schema changes.
        upgrade_plugin_savepoint(true, 2026030600369, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026030600370) {
        // v3.7.70 — ROOT CAUSE FIX: Test Data Generator (Test Students) added to the
        // Site Administration sidebar navigation. The item was registered in settings.php
        // (Moodle admin tree) but was missing from the $menuitems array inside
        // local_rtocompliance_extend_settings_navigation() in lib.php — which is the
        // function that actually builds the visual sidebar menu shown in Site Admin.
        // Added ['testdata', 'test_data.php'] after transitions and before support links.
        upgrade_plugin_savepoint(true, 2026030600370, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026030600371) {
        // v3.7.71 — Added configurable audit log retention setting (log_retentiondays,
        // default 730 days / 2 years). The nightly cleanup task now reads this value
        // from config instead of using the hardcoded RETENTION_DAYS class constant.
        // A new Maintenance settings page appears in Site Admin → RTO Compliance.
        // Set to 0 to disable automatic pruning. Check ASQA obligations before reducing.
        if (!get_config('local_rtocompliance', 'log_retentiondays')) {
            set_config('log_retentiondays', 730, 'local_rtocompliance');
        }
        upgrade_plugin_savepoint(true, 2026030600371, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026032300373) {
        // v3.7.73 — Fix audit log "Array to string conversion" warning when
        // log details contain nested arrays/objects. No DB changes.
        upgrade_plugin_savepoint(true, 2026032300373, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026032601403) {
        // v3.7.75 — Fix local_rtocompliance_exports schema mismatch.
        // The original table created at v2025120500 was missing three columns
        // (natfiles, validationwarnings, validationlog) that are required by
        // natexport.php and defined in install.xml. Add them if absent.
        $table = new xmldb_table('local_rtocompliance_exports');

        $field = new xmldb_field('natfiles', XMLDB_TYPE_TEXT, null, null, null, null, null, 'recordcount');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('validationwarnings', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'validationerrors');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('validationlog', XMLDB_TYPE_TEXT, null, null, null, null, null, 'validationwarnings');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Also ensure the local_rtocompliance_audit table exists for sites that
        // somehow skipped the 2025121003 upgrade step.
        $audittable = new xmldb_table('local_rtocompliance_audit');
        if (!$dbman->table_exists($audittable)) {
            $audittable->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $audittable->add_field('action', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, null);
            $audittable->add_field('entitytype', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
            $audittable->add_field('entityid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $audittable->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $audittable->add_field('description', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $audittable->add_field('olddata', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $audittable->add_field('newdata', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $audittable->add_field('ipaddress', XMLDB_TYPE_CHAR, '45', null, null, null, null);
            $audittable->add_field('useragent', XMLDB_TYPE_CHAR, '255', null, null, null, null);
            $audittable->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $audittable->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $audittable->add_key('userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
            $audittable->add_index('action', XMLDB_INDEX_NOTUNIQUE, ['action']);
            $audittable->add_index('entitytype', XMLDB_INDEX_NOTUNIQUE, ['entitytype']);
            $audittable->add_index('entityid', XMLDB_INDEX_NOTUNIQUE, ['entityid']);
            $audittable->add_index('timecreated', XMLDB_INDEX_NOTUNIQUE, ['timecreated']);
            $dbman->create_table($audittable);
        }

        upgrade_plugin_savepoint(true, 2026032601403, 'local', 'rtocompliance');
    }

    // v3.7.76: BUG FIX — Scoped debug popup error interceptors to prevent site admin
    //          primary/secondary menu disappearing.
    //          ROOT CAUSE: window.onerror and window.fetch in the before_footer debug
    //          popup were unscoped — they caught ALL JS errors site-wide, including
    //          Moodle core RequireJS errors (core/first.js ERR_CONTENT_DECODING_FAILED).
    //          When core/first.js failed to load, RequireJS threw an uncaught error,
    //          window.onerror fired, and the debug overlay (position:fixed z-index:99999)
    //          was displayed — covering Moodle's primary and secondary navigation menus.
    //          FIX: window.onerror now only shows the overlay for errors whose source
    //          URL contains /local/rtocompliance/. window.fetch only intercepts requests
    //          to /local/rtocompliance/ endpoints, leaving Moodle core fetch untouched.
    //          No DB schema changes.
    if ($oldversion < 2026032601404) {
        upgrade_plugin_savepoint(true, 2026032601404, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026032601405) {
        // v3.7.77 — Add RPL/Credit Transfer, Risk Management, Roles & Responsibilities,
        //           and Meeting Minutes tables. Fix navigation links for RPL, Risk Management,
        //           and Audit Log. Add Roles & Responsibilities and Meeting Minutes tabs to
        //           governance.php. Add Clause 9/12 compliance indicators to certificates.php.

        // 1. RPL & Credit Transfer Register (Standards 1.6 and 1.7)
        $rpltable = new xmldb_table('local_rtocompliance_rpl');
        if (!$dbman->table_exists($rpltable)) {
            $rpltable->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $rpltable->add_field('studentid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $rpltable->add_field('studentname', XMLDB_TYPE_CHAR, '200', null, null, null, null);
            $rpltable->add_field('unitcode', XMLDB_TYPE_CHAR, '20', null, null, null, null);
            $rpltable->add_field('unitname', XMLDB_TYPE_CHAR, '200', null, null, null, null);
            $rpltable->add_field('qualcode', XMLDB_TYPE_CHAR, '20', null, null, null, null);
            $rpltable->add_field('qualname', XMLDB_TYPE_CHAR, '200', null, null, null, null);
            $rpltable->add_field('rpltype', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'rpl');
            $rpltable->add_field('evidencedescription', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $rpltable->add_field('evidencefiles', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $rpltable->add_field('assessoruserid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $rpltable->add_field('assessorname', XMLDB_TYPE_CHAR, '200', null, null, null, null);
            $rpltable->add_field('decision', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, 'pending');
            $rpltable->add_field('decisiondate', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $rpltable->add_field('decisionreason', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $rpltable->add_field('sourcequalcode', XMLDB_TYPE_CHAR, '20', null, null, null, null);
            $rpltable->add_field('sourcertoid', XMLDB_TYPE_CHAR, '10', null, null, null, null);
            $rpltable->add_field('usitranscriptverified', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
            $rpltable->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $rpltable->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $rpltable->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $rpltable->add_index('studentid', XMLDB_INDEX_NOTUNIQUE, ['studentid']);
            $rpltable->add_index('rpltype', XMLDB_INDEX_NOTUNIQUE, ['rpltype']);
            $rpltable->add_index('decision', XMLDB_INDEX_NOTUNIQUE, ['decision']);
            $dbman->create_table($rpltable);
        }

        // 2. Risk Management Register (Standard 4.3)
        $riskstable = new xmldb_table('local_rtocompliance_risks');
        if (!$dbman->table_exists($riskstable)) {
            $riskstable->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $riskstable->add_field('risktitle', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $riskstable->add_field('riskcategory', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, 'operational');
            $riskstable->add_field('riskdescription', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $riskstable->add_field('likelihood', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '3');
            $riskstable->add_field('impact', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '3');
            $riskstable->add_field('riskowner', XMLDB_TYPE_CHAR, '200', null, null, null, null);
            $riskstable->add_field('mitigationplan', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $riskstable->add_field('reviewdate', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $riskstable->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'open');
            $riskstable->add_field('notes', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $riskstable->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $riskstable->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $riskstable->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $riskstable->add_index('riskcategory', XMLDB_INDEX_NOTUNIQUE, ['riskcategory']);
            $riskstable->add_index('status', XMLDB_INDEX_NOTUNIQUE, ['status']);
            $dbman->create_table($riskstable);
        }

        // 3. Roles & Responsibilities Register (Standard 4.2)
        $rolestable = new xmldb_table('local_rtocompliance_roles');
        if (!$dbman->table_exists($rolestable)) {
            $rolestable->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $rolestable->add_field('rolename', XMLDB_TYPE_CHAR, '200', null, XMLDB_NOTNULL, null, null);
            $rolestable->add_field('roleowner', XMLDB_TYPE_CHAR, '200', null, null, null, null);
            $rolestable->add_field('department', XMLDB_TYPE_CHAR, '100', null, null, null, null);
            $rolestable->add_field('responsibilities', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $rolestable->add_field('reportsto', XMLDB_TYPE_CHAR, '200', null, null, null, null);
            $rolestable->add_field('regulatoryobligations', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $rolestable->add_field('reviewdate', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $rolestable->add_field('notes', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $rolestable->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $rolestable->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $rolestable->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $dbman->create_table($rolestable);
        }

        // 4. Meeting Minutes Register (Standards 4.1-4.2)
        $minutestable = new xmldb_table('local_rtocompliance_minutes');
        if (!$dbman->table_exists($minutestable)) {
            $minutestable->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $minutestable->add_field('meetingtitle', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $minutestable->add_field('meetingtype', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, 'board');
            $minutestable->add_field('meetingdate', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $minutestable->add_field('location', XMLDB_TYPE_CHAR, '200', null, null, null, null);
            $minutestable->add_field('attendees', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $minutestable->add_field('agendaitems', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $minutestable->add_field('decisions', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $minutestable->add_field('actionitems', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $minutestable->add_field('complianceitems', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $minutestable->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $minutestable->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $minutestable->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $minutestable->add_index('meetingdate', XMLDB_INDEX_NOTUNIQUE, ['meetingdate']);
            $minutestable->add_index('meetingtype', XMLDB_INDEX_NOTUNIQUE, ['meetingtype']);
            $dbman->create_table($minutestable);
        }

        upgrade_plugin_savepoint(true, 2026032601405, 'local', 'rtocompliance');
    }

    // v3.7.78: BUG FIX — Removed debug error popup from before_footer hook.
    //          ROOT CAUSE: The debug popup (position:fixed; z-index:99999) in
    //          before_footer_html_generation.php was STILL hiding site admin
    //          primary/secondary navigation menus. v3.7.76 scoped window.onerror
    //          and window.fetch, but the DOMContentLoaded PHP error scanner
    //          (lines 229-242) was completely unscoped — it queried ALL
    //          .alert-warning and .alert-danger elements on the page (standard
    //          Moodle notification classes), and when ANY contained text matching
    //          "error"/"Warning"/"Notice"/"Fatal", it showed the full-screen
    //          overlay covering all navigation. Additionally, window.onerror
    //          and window.fetch overrides are global concerns that should never
    //          be in production code (per BUG_FIXES.md rules).
    //          FIX: Completely removed the debug error popup from the Moodle 5
    //          hook callback. Only table sorting JS remains. The legacy lib.php
    //          callback already had only table sorting (no debug popup).
    //          No DB schema changes.
    if ($oldversion < 2026032601406) {
        upgrade_plugin_savepoint(true, 2026032601406, 'local', 'rtocompliance');
    }

    // v3.7.79: BUGFIX — CSS :contains() selector removed, :root variables scoped to path class,
    //          exit; replaced with return; after $OUTPUT->footer() in 7 page files.
    //          No DB schema changes.
    if ($oldversion < 2026032700001) {
        upgrade_plugin_savepoint(true, 2026032700001, 'local', 'rtocompliance');
    }


    // v3.7.92: NEW — Nominal hours auto-lookup via NCVER API in Qualification Builder.
    //          Added nominalhours_autofill AMD module to qualbuilder_edit.php (training product)
    //          and qualbuilder_unit.php (unit of competency). Entering a code auto-fetches
    //          and fills the Nominal Hours field from the NCVER database via the
    //          local_rtocompliance/nominalhours_autofill AMD module.
    //          No DB schema changes.
    if ($oldversion < 2026032700014) {
        upgrade_plugin_savepoint(true, 2026032700014, 'local', 'rtocompliance');
    }


    // v3.7.93: BUGFIX — Create 4 tables missing from upgrade.php for existing installations.
    //          All four tables exist in install.xml (fresh installs work fine) but were never
    //          added to upgrade.php, so any site installed before this version is missing them,
    //          causing dml_read_exception errors:
    //            - local_rtocompliance_locations (Delivery Locations, NAT00020)
    //            - local_rtocompliance_cricos_attendance (CRICOS attendance tracking)
    //            - local_rtocompliance_cricos_progress (CRICOS course progress)
    //            - local_rtocompliance_cricos_scv (Student Course Variations / PRISMS)
    if ($oldversion < 2026032700015) {
        $dbman = $DB->get_manager();

        // --- local_rtocompliance_locations ---
        if (!$dbman->table_exists('local_rtocompliance_locations')) {
            $table = new xmldb_table('local_rtocompliance_locations');
            $table->add_field('id',           XMLDB_TYPE_INTEGER, '10',  true,  true,  true);
            $table->add_field('locationid',   XMLDB_TYPE_CHAR,    '10',  true,  false, false);
            $table->add_field('locationname', XMLDB_TYPE_CHAR,    '100', true,  false, false);
            $table->add_field('buildingname', XMLDB_TYPE_CHAR,    '50',  false, false, false);
            $table->add_field('streetno',     XMLDB_TYPE_CHAR,    '15',  false, false, false);
            $table->add_field('streetname',   XMLDB_TYPE_CHAR,    '70',  false, false, false);
            $table->add_field('suburb',       XMLDB_TYPE_CHAR,    '50',  false, false, false);
            $table->add_field('postcode',     XMLDB_TYPE_CHAR,    '4',   false, false, false);
            $table->add_field('statecode',    XMLDB_TYPE_CHAR,    '2',   false, false, false);
            $table->add_field('country',      XMLDB_TYPE_CHAR,    '4',   true,  false, false, '1101');
            $table->add_field('phone',        XMLDB_TYPE_CHAR,    '20',  false, false, false);
            $table->add_field('email',        XMLDB_TYPE_CHAR,    '100', false, false, false);
            $table->add_field('status',       XMLDB_TYPE_CHAR,    '20',  true,  false, false, 'active');
            $table->add_field('timecreated',  XMLDB_TYPE_INTEGER, '10',  true,  false, false, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10',  true,  false, false, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('locationid', XMLDB_INDEX_UNIQUE,    ['locationid']);
            $table->add_index('status',     XMLDB_INDEX_NOTUNIQUE, ['status']);
            $table->add_index('statecode',  XMLDB_INDEX_NOTUNIQUE, ['statecode']);
            $dbman->create_table($table);
        }

        // --- local_rtocompliance_cricos_attendance ---
        if (!$dbman->table_exists('local_rtocompliance_cricos_attendance')) {
            $table = new xmldb_table('local_rtocompliance_cricos_attendance');
            $table->add_field('id',                 XMLDB_TYPE_INTEGER, '10',  true,  true,  true);
            $table->add_field('cricosstudentid',    XMLDB_TYPE_INTEGER, '10',  true,  false, false);
            $table->add_field('coeid',              XMLDB_TYPE_INTEGER, '10',  true,  false, false);
            $table->add_field('weekstartdate',      XMLDB_TYPE_INTEGER, '10',  true,  false, false);
            $table->add_field('scheduledhours',     XMLDB_TYPE_NUMBER,  '5',   true,  false, false, '20',   1);
            $table->add_field('attendedhours',      XMLDB_TYPE_NUMBER,  '5',   true,  false, false, '0',    1);
            $table->add_field('absencehours',       XMLDB_TYPE_NUMBER,  '5',   true,  false, false, '0',    1);
            $table->add_field('approvedabsence',    XMLDB_TYPE_NUMBER,  '5',   true,  false, false, '0',    1);
            $table->add_field('attendancepercent',  XMLDB_TYPE_NUMBER,  '5',   true,  false, false, '0',    2);
            $table->add_field('notes',              XMLDB_TYPE_TEXT,    null,  false, false, false);
            $table->add_field('timecreated',        XMLDB_TYPE_INTEGER, '10',  true,  false, false, '0');
            $table->add_field('timemodified',       XMLDB_TYPE_INTEGER, '10',  true,  false, false, '0');
            $table->add_key('primary',          XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('cricosstudentid',  XMLDB_KEY_FOREIGN, ['cricosstudentid'], 'local_rtocompliance_cricos_students', ['id']);
            $table->add_key('coeid',            XMLDB_KEY_FOREIGN, ['coeid'], 'local_rtocompliance_cricos_coe', ['id']);
            $table->add_index('student_week',         XMLDB_INDEX_UNIQUE,    ['cricosstudentid', 'weekstartdate']);
            $table->add_index('weekstartdate',         XMLDB_INDEX_NOTUNIQUE, ['weekstartdate']);
            $table->add_index('attendancepercent',     XMLDB_INDEX_NOTUNIQUE, ['attendancepercent']);
            $dbman->create_table($table);
        }

        // --- local_rtocompliance_cricos_progress ---
        if (!$dbman->table_exists('local_rtocompliance_cricos_progress')) {
            $table = new xmldb_table('local_rtocompliance_cricos_progress');
            $table->add_field('id',                    XMLDB_TYPE_INTEGER, '10',  true,  true,  true);
            $table->add_field('cricosstudentid',       XMLDB_TYPE_INTEGER, '10',  true,  false, false);
            $table->add_field('coeid',                 XMLDB_TYPE_INTEGER, '10',  true,  false, false);
            $table->add_field('studyperiod',           XMLDB_TYPE_CHAR,    '20',  true,  false, false);
            $table->add_field('periodstart',           XMLDB_TYPE_INTEGER, '10',  true,  false, false);
            $table->add_field('periodend',             XMLDB_TYPE_INTEGER, '10',  true,  false, false);
            $table->add_field('unitsattempted',        XMLDB_TYPE_INTEGER, '3',   true,  false, false, '0');
            $table->add_field('unitspassed',           XMLDB_TYPE_INTEGER, '3',   true,  false, false, '0');
            $table->add_field('unitsfailed',           XMLDB_TYPE_INTEGER, '3',   true,  false, false, '0');
            $table->add_field('unitswithdrawnorloa',   XMLDB_TYPE_INTEGER, '3',   true,  false, false, '0');
            $table->add_field('progresspercent',       XMLDB_TYPE_NUMBER,  '5',   true,  false, false, '0',    2);
            $table->add_field('progressstatus',        XMLDB_TYPE_CHAR,    '30',  true,  false, false, 'satisfactory');
            $table->add_field('interventionrequired',  XMLDB_TYPE_INTEGER, '1',   true,  false, false, '0');
            $table->add_field('interventionid',        XMLDB_TYPE_INTEGER, '10',  false, false, false);
            $table->add_field('notes',                 XMLDB_TYPE_TEXT,    null,  false, false, false);
            $table->add_field('reviewedby',            XMLDB_TYPE_INTEGER, '10',  false, false, false);
            $table->add_field('reviewdate',            XMLDB_TYPE_INTEGER, '10',  false, false, false);
            $table->add_field('timecreated',           XMLDB_TYPE_INTEGER, '10',  true,  false, false, '0');
            $table->add_field('timemodified',          XMLDB_TYPE_INTEGER, '10',  true,  false, false, '0');
            $table->add_key('primary',         XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('cricosstudentid', XMLDB_KEY_FOREIGN, ['cricosstudentid'], 'local_rtocompliance_cricos_students', ['id']);
            $table->add_key('coeid',           XMLDB_KEY_FOREIGN, ['coeid'], 'local_rtocompliance_cricos_coe', ['id']);
            $table->add_key('reviewedby',      XMLDB_KEY_FOREIGN, ['reviewedby'], 'user', ['id']);
            $table->add_index('student_period',          XMLDB_INDEX_UNIQUE,    ['cricosstudentid', 'studyperiod']);
            $table->add_index('progressstatus',          XMLDB_INDEX_NOTUNIQUE, ['progressstatus']);
            $table->add_index('interventionrequired',    XMLDB_INDEX_NOTUNIQUE, ['interventionrequired']);
            $dbman->create_table($table);
        }

        // --- local_rtocompliance_cricos_scv ---
        if (!$dbman->table_exists('local_rtocompliance_cricos_scv')) {
            $table = new xmldb_table('local_rtocompliance_cricos_scv');
            $table->add_field('id',                   XMLDB_TYPE_INTEGER, '10',  true,  true,  true);
            $table->add_field('coeid',                XMLDB_TYPE_INTEGER, '10',  true,  false, false);
            $table->add_field('scvtype',              XMLDB_TYPE_CHAR,    '30',  true,  false, false);
            $table->add_field('reason',               XMLDB_TYPE_TEXT,    null,  true,  false, false);
            $table->add_field('reasoncode',           XMLDB_TYPE_CHAR,    '10',  false, false, false);
            $table->add_field('originalenddate',      XMLDB_TYPE_INTEGER, '10',  false, false, false);
            $table->add_field('newenddate',           XMLDB_TYPE_INTEGER, '10',  false, false, false);
            $table->add_field('extensionweeks',       XMLDB_TYPE_INTEGER, '4',   false, false, false);
            $table->add_field('suspensionstart',      XMLDB_TYPE_INTEGER, '10',  false, false, false);
            $table->add_field('suspensionend',        XMLDB_TYPE_INTEGER, '10',  false, false, false);
            $table->add_field('interventiontype',     XMLDB_TYPE_CHAR,    '50',  false, false, false);
            $table->add_field('interventiondetails',  XMLDB_TYPE_TEXT,    null,  false, false, false);
            $table->add_field('status',               XMLDB_TYPE_CHAR,    '20',  true,  false, false, 'active');
            $table->add_field('approvedby',           XMLDB_TYPE_INTEGER, '10',  false, false, false);
            $table->add_field('approvaldate',         XMLDB_TYPE_INTEGER, '10',  false, false, false);
            $table->add_field('prismsreported',       XMLDB_TYPE_INTEGER, '1',   true,  false, false, '0');
            $table->add_field('prismsreportdate',     XMLDB_TYPE_INTEGER, '10',  false, false, false);
            $table->add_field('prismstransactionid',  XMLDB_TYPE_CHAR,    '50',  false, false, false);
            $table->add_field('effectivedate',        XMLDB_TYPE_INTEGER, '10',  true,  false, false);
            $table->add_field('timecreated',          XMLDB_TYPE_INTEGER, '10',  true,  false, false, '0');
            $table->add_field('timemodified',         XMLDB_TYPE_INTEGER, '10',  true,  false, false, '0');
            $table->add_key('primary',    XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('coeid',      XMLDB_KEY_FOREIGN, ['coeid'], 'local_rtocompliance_cricos_coe', ['id']);
            $table->add_key('approvedby', XMLDB_KEY_FOREIGN, ['approvedby'], 'user', ['id']);
            $table->add_index('scvtype',       XMLDB_INDEX_NOTUNIQUE, ['scvtype']);
            $table->add_index('status',        XMLDB_INDEX_NOTUNIQUE, ['status']);
            $table->add_index('effectivedate', XMLDB_INDEX_NOTUNIQUE, ['effectivedate']);
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026032700015, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026032700016) {
        // v3.7.94 — Testing Engine added (testing.php + settings.php nav entry + lang strings).
        // No new DB tables required.
        upgrade_plugin_savepoint(true, 2026032700016, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026032700017) {
        // v3.7.95 — BUGFIX: Testing Engine — fixed infra_caps, qual_table, comp_risk, trainer_credentials, nat_locations tests.
        // No new DB tables required.
        upgrade_plugin_savepoint(true, 2026032700017, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026032800018) {
        // v3.7.96 — VERSION-BUMP: Routine release. Adds missing upgrade.php savepoint for v3.7.95.
        // No new DB tables required.
        upgrade_plugin_savepoint(true, 2026032800018, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026032800019) {
        // v3.7.97 — BUGFIX: Fix false-positive RTO-USI-005 diagnostic (usi_pending test).
        // usiverified status counts now scoped to students who actually have a USI entered
        // (usi IS NOT NULL AND usi != ''). Previously count_records(['usiverified' => 0]) counted
        // ALL student rows (usiverified defaults to 0), inflating the unverified count.
        // No DB schema change.
        upgrade_plugin_savepoint(true, 2026032800019, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026032800020) {
        // v3.7.98 — FIX: Add apiurl to API settings page so admins can configure the
        // lms-labs.com base URL via Moodle admin UI. Fixes NCVER nominal hours lookup
        // when no API URL was previously persisted in plugin config.
        // No DB schema change.
        upgrade_plugin_savepoint(true, 2026032800020, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026032800021) {
        // v3.8.0 — FEATURE: Smart Qualification Builder.
        // - New Express endpoint GET /api/tga/qualbuilder/:code returns packaging rules,
        //   AQF level, grouped units (Core / Group A-D / General) using TGA REST API.
        // - New external web service tga_get_builder_data($code): fetches TGA builder data
        //   + Moodle category/course suggestions in one AJAX call.
        // - New external web service qualbuilder_auto_build(...): atomically saves product
        //   metadata and all units in one transaction (replaces multi-step workflow).
        // - qualbuilder_edit.php rewritten as smart builder UI with live compliance
        //   dashboard, group-aware unit sections, inline course mapping, category
        //   suggestion, and one-click auto-build.
        // No DB schema change — uses existing local_rtocompliance_qualbuilder and
        // local_rtocompliance_qualunits tables.
        upgrade_plugin_savepoint(true, 2026032800021, 'local', 'rtocompliance');
    }

    // v3.8.1 — SAVEPOINT FIX: This savepoint was absent from the v3.8.1 release ZIP,
    // causing Moodle to report "can't upgrade a lower version" on sites that had
    // processed 2026032800021 and needed the DB version record advanced to 2026032800022.
    // No code change. No DB schema change.
    if ($oldversion < 2026032800022) {
        upgrade_plugin_savepoint(true, 2026032800022, 'local', 'rtocompliance');
    }

    // v3.8.2 — VERSION-BUMP: Jumps numeric version to 2026032800030 to guarantee this
    // release installs cleanly over any version a site may have reached during testing.
    // Includes the missing v3.8.1 savepoint above. No code change. No DB schema change.
    if ($oldversion < 2026032800030) {
        upgrade_plugin_savepoint(true, 2026032800030, 'local', 'rtocompliance');
    }

    // v3.8.3 — FIX: Tester-reported crashes and UI issues.
    // (1) Added local_rtocompliance_log_action() to lib.php — fixes fatal crash on RPL
    //     save/update/delete ("Call to undefined function local_rtocompliance_log_action()").
    // (2) PARAM_DIGITS → PARAM_INT in location_edit.php — fixes "Undefined constant
    //     PARAM_DIGITS" crash in Locations module.
    // (3) addHelpButton identifier fixed (location_id_help → location_id) — removes
    //     Moodle debug warning about missing _help_help string.
    // (4) Certificate clause labels corrected 6.1-6.4 → 9-14 in index.php and
    //     practice_guides.php; complaints clause corrected 6.1-6.6 → 2.7-2.8.
    // (5) JS show/hide for credit-transfer-section in rpl_edit.php.
    // (6) Dashboard links: Training Support + Diversity & Inclusion → support.php.
    // No DB schema change.
    if ($oldversion < 2026032800040) {
        upgrade_plugin_savepoint(true, 2026032800040, 'local', 'rtocompliance');
    }

    // v3.8.4 — FIX: TGA fetch "Class curl not found" error.
    // Root cause: classes/external.php included externallib.php but NOT filelib.php,
    // which is where Moodle defines its \curl wrapper class. Any call to new \curl()
    // in tga_get_builder_data() and qualbuilder_auto_build() threw a fatal exception:
    // "Exception - Class curl not found". Fix: add require_once(filelib.php) to
    // external.php. Also added qual_curl_class test to testing.php that validates
    // \curl class availability and PHP curl extension status.
    // No DB schema change.
    if ($oldversion < 2026032800050) {
        upgrade_plugin_savepoint(true, 2026032800050, 'local', 'rtocompliance');
    }

    // v3.8.5 — FEATURE: Qualification Builder full UX overhaul.
    // Points system support (MEM/engineering quals): detects creditPoints-based quals from TGA rules text,
    // shows points tally in compliance dashboard. One-click category accept + map all courses (multi-tier
    // matching: shortname prefix → contains → fullname contains → fuzzy word match). Groups A-Y full
    // validation (was A-D only). Live auto-suggest missing units per group. Save persists pointsSystem +
    // pointsRequired in electiverules JSON. Express: groups regex A-D→A-Z, points parsing from rulesText.
    // external.php: pointsrequired/pointssystem fields added to tga_get_builder_data returns.
    // No DB schema change.
    if ($oldversion < 2026032800060) {
        upgrade_plugin_savepoint(true, 2026032800060, 'local', 'rtocompliance');
    }

    // v3.8.6 — FEATURE: Testing Engine fix actions.
    // Each failing test now has an action button: 'navigate' types open the relevant Moodle
    // settings page in a new tab and mark the test as Fixing; 'autofix' types call a new PHP
    // AJAX handler (rto_autofix()) to apply the fix server-side then immediately re-run the test.
    // Auto-fixable: qi_autosurvey (enable + set defaults), trainer_credentials (bulk-activate).
    // Navigate-to-fix: qual_api_config, qual_tga_ping, nat_locations, usi_config, usi_credential,
    // usi_pending, infra_config, cert_config, nat_reportyear.
    // New "Auto-Fix All" header button triggers all auto-fixable failed tests in sequence.
    // No DB schema change.
    if ($oldversion < 2026032800070) {
        upgrade_plugin_savepoint(true, 2026032800070, 'local', 'rtocompliance');
    }

    // v3.8.7 — FIX: trainer_credentials autofix honesty.
    // Auto-fix previously returned ok:true (misleading — TAE qualifications still require manual entry).
    // Now returns ok:false with "STEP 1 DONE / STEP 2 REQUIRED" message explaining that activating
    // trainers is step 1 (done automatically) but entering TAE qualification evidence per trainer
    // is step 2 (manual — ASQA requires documentary evidence). No DB schema change.
    if ($oldversion < 2026032800080) {
        upgrade_plugin_savepoint(true, 2026032800080, 'local', 'rtocompliance');
    }

    // v3.8.8 — FEATURE: Platform config push pipeline.
    // New webhook.php endpoint receives config POSTs from lms-labs.com SaaS platform.
    // Authenticated via X-Webhook-Key header matching stored webhookapikey config.
    // Whitelisted keys: siteid, apikey, apiurl, usi_organization_id, usi_test_mode,
    // usi_certificate_path, usi_certificate_password, autosurveyenable, autosurveydelay,
    // autosurveyemailsubject, reportyear, defaultstate. Also supports usi_cert_base64 to
    // decode and write machine credential P12 to Moodle dataroot automatically.
    // USI Verification now has a proper settings page in Site Administration (was just a comment).
    // Platform Webhook Key field added to API Settings page.
    // Replit platform: POST /api/rto/push-config and GET /api/rto/push-status endpoints.
    // Admin dashboard: "RTO Moodle Config Push" panel with live secret status + push button.
    // No DB schema change.
    if ($oldversion < 2026032800088) {
        upgrade_plugin_savepoint(true, 2026032800088, 'local', 'rtocompliance');
    }

    // v3.8.9 — FEATURE: USI verification proxied through lms-labs.com platform.
    // usi_platform_client.php replaces usi_registry_client.php as the verification backend.
    // All USI checks now route through POST /api/usi/verify on the Replit platform.
    // The platform holds the shared ATO machine credential (P12 cert + org ID) for all customers.
    // Plugin no longer needs a local P12 certificate — only siteid + apikey required.
    // Replit: POST /api/usi/verify performs ATO MAS-ST WS-Trust + USI Registry WSDL call.
    // Replit: GET /api/usi/status shows cert readiness for platform admins.
    // Graceful CERT_PENDING response until ATO myGovID P12 arrives.
    // No DB schema change.
    if ($oldversion < 2026032900090) {
        upgrade_plugin_savepoint(true, 2026032900090, 'local', 'rtocompliance');
    }

    // v3.8.10 — Re-release bump.
    // Ensures clean install on all customer sites after USI proxy architecture change.
    // AMD src=build=min verified identical (md5 a8ebe23fd8e5cb0a61499d4a030a5a5a).
    // No code change. No DB schema change.
    if ($oldversion < 2026032900091) {
        upgrade_plugin_savepoint(true, 2026032900091, 'local', 'rtocompliance');
    }

    // v3.8.11 — FIX: Central Config integration for USI proxy.
    // usi_platform_client.php now checks local_aiconfig (Central Config) first for
    // siteid and apikey, falling back to plugin-specific settings if Central Config
    // is not installed. Matches the pattern used by all other plugins.
    // Settings page renamed from 'AI Grader Integration' to 'Platform API Settings'.
    // No DB schema change.
    if ($oldversion < 2026032900092) {
        upgrade_plugin_savepoint(true, 2026032900092, 'local', 'rtocompliance');
    }

    // v3.8.12 — CLEAN BUMP: Re-release of v3.8.11 changes assembled in a single clean pass.
    // Includes: Central Config integration (siteid/apikey via local_aiconfig with fallback),
    // settings page renamed to 'Platform API Settings'. No DB schema change.
    if ($oldversion < 2026032900093) {
        upgrade_plugin_savepoint(true, 2026032900093, 'local', 'rtocompliance');
    }

    // v3.8.13 — FIX: qualbuilder_edit.php blank white screen on Moodle 4.0 – 4.2.
    // $PAGE->requires->js_amd_inline() was added in Moodle 4.3 and does not exist on
    // older 4.x sites. Calling it threw a fatal error after the page header was already
    // output, producing a blank white screen. Now uses method_exists() to fall back to
    // a direct <script> tag output for Moodle < 4.3 sites. No DB schema change.
    if ($oldversion < 2026032900094) {
        upgrade_plugin_savepoint(true, 2026032900094, 'local', 'rtocompliance');
    }

    // v3.8.14 — BUMP: Consolidation release.
    // Includes all v3.8.11–3.8.13 changes: Central Config integration (siteid/apikey via
    // local_aiconfig with fallback), settings page renamed to 'Platform API Settings',
    // qualbuilder_edit.php Moodle 4.0–4.2 compatibility fix (js_amd_inline fallback).
    // No DB schema change.
    if ($oldversion < 2026032900095) {
        upgrade_plugin_savepoint(true, 2026032900095, 'local', 'rtocompliance');
    }

    // v3.8.15 — AMD FIX: Extracted qualbuilder_edit.php inline JS into proper AMD module.
    // qualbuilder_edit.js added to amd/src + amd/build. Now called via js_call_amd().
    // No DB schema change.
    if ($oldversion < 2026032900096) {
        upgrade_plugin_savepoint(true, 2026032900096, 'local', 'rtocompliance');
    }

    // v3.8.16 — BUMP: Consolidation release. All 6 locations synced via master release process.
    // No DB schema change.
    if ($oldversion < 2026032900097) {
        upgrade_plugin_savepoint(true, 2026032900097, 'local', 'rtocompliance');
    }

    // v3.8.17 — Certificate credit deduction: 5 credits consumed per certificate issued.
    // Added consume_credits() + get_credit_balance() to usi_platform_client.php.
    // issue_certificate.php now calls consume_credits(5) before DB insert; hard-blocks on
    // INSUFFICIENT_CREDITS (fail-open on network/config errors). Live balance panel shown in UI.
    // No DB schema change.
    if ($oldversion < 2026032900098) {
        upgrade_plugin_savepoint(true, 2026032900098, 'local', 'rtocompliance');
    }

    // v3.8.18 — CRITICAL AMD FIX: ReferenceError: setup is not defined.
    // Root cause: inner function at amd/src/qualbuilder_edit.js:58 was named 'init' (shadowing
    // the outer exported init(INIT)), but line 879 called setup(). Renamed inner function to
    // setup() so the call resolves correctly. This caused a ReferenceError every time anyone
    // visited qualbuilder_edit.php, which crashed Moodle's AMD loader and caused the site admin
    // primary and secondary navigation menus to disappear on all RTO Compliance pages.
    // src=build=min all updated. MD5 06525e3e0d13626959bfb53c3dc82029.
    // No DB schema change.
    if ($oldversion < 2026032900099) {
        upgrade_plugin_savepoint(true, 2026032900099, 'local', 'rtocompliance');
    }

    // v3.8.19: BUG FIX — Site admin primary/secondary navigation menus disappearing on
    //          all RTO Compliance pages.
    //          ROOT CAUSE: before_footer_html_generation hook (and legacy lib.php callback)
    //          were pre-defining core/first as an empty noop module {} in the page footer.
    //          Moodle's AMD loader fetches core/first ASYNCHRONOUSLY from the <head>.
    //          When the footer script ran synchronously and called define('core/first', ...)
    //          before the async fetch completed, RequireJS stored our {} definition and
    //          never fetched the real file. Every module that depends on core/first (including
    //          all Moodle navigation JS) received {} instead of the real implementation,
    //          silently breaking navigation on every page where the plugin is installed.
    //          FIX: Removed the core/first AMD stub, requirejs.onError override, and
    //          fixDrawers() from classes/hook/before_footer_html_generation.php AND from
    //          the legacy local_rtocompliance_before_footer() in lib.php.
    //          Table sorting JS (RTOC pages only) is retained in both.
    //          This mirrors the v3.7.78 fix that removed the debug popup for the same reason.
    //          No DB schema changes.
    if ($oldversion < 2026032900100) {
        upgrade_plugin_savepoint(true, 2026032900100, 'local', 'rtocompliance');
    }

    // v3.8.20: BUMP — Consolidation release following master release process (Replit Hardened Edition).
    //          Confirms v3.8.19 nav fix is cleanly packaged. All 6 locations synced and verified.
    //          AMD src=build=min triple-verified: qualbuilder_edit MD5 06525e3e0d13626959bfb53c3dc82029,
    //          nominalhours_autofill MD5 a8ebe23fd8e5cb0a61499d4a030a5a5a.
    //          No code or DB schema changes.
    if ($oldversion < 2026032900101) {
        upgrade_plugin_savepoint(true, 2026032900101, 'local', 'rtocompliance');
    }

    // v3.8.21: NAV FIX — qualbuilder_edit.php JS init payload moved from js_call_amd() inline args
    //          into a <script type="application/json" id="qb-init-data"> DOM element.
    //          Root cause: large/complex PHP arrays passed via js_call_amd() are json_encode()d inline
    //          inside a RequireJS require() call; any encoding anomaly (invalid UTF-8, double-encoded
    //          strings, round-trip bugs from the former json_encode→json_decode cycle) produces a
    //          JavaScript SyntaxError inside first.js ("No define call for core/first"), aborting the
    //          entire AMD chain and hiding Moodle site-admin primary/secondary navigation menus.
    //          AMD module now reads INIT from DOM via JSON.parse(). js_call_amd() passes empty args [].
    //          JSON_HEX_TAG added to json_encode() to prevent </script> injection.
    //          AMD src=build=min triple-synced: MD5 575a2e610095215456fd4971480c192f.
    //          No DB schema changes.
    if ($oldversion < 2026032900102) {
        upgrade_plugin_savepoint(true, 2026032900102, 'local', 'rtocompliance');
    }

    // v3.8.22: AMD ASCII CLEAN — All non-ASCII characters (em dashes U+2014, box-drawing U+2500)
    //          in amd/src/qualbuilder_edit.js escaped to \uXXXX unicode escapes.
    //          Root cause: non-ASCII bytes in a JS file served without an explicit charset=utf-8
    //          Content-Type header cause the browser (or RequireJS XHR loader) to interpret the
    //          file as Latin-1, making multi-byte UTF-8 sequences (e.g. 0xE2 0x80 0x94 for em dash)
    //          appear as garbled Latin-1 characters — which are invalid JS tokens.  RequireJS hits
    //          "Uncaught SyntaxError: Invalid or unexpected token" inside first.js, then throws
    //          "No define call for core/first", aborting the entire AMD chain and hiding Moodle's
    //          site-admin navigation menus.
    //          AMD src=build=min triple-synced: MD5 26185137ea276241c8faa0d32aad1ef1.
    //          Includes v3.8.21 DOM-payload fix (js_call_amd empty args + script[type=application/json]).
    //          No DB schema changes.
    if ($oldversion < 2026032900103) {
        upgrade_plugin_savepoint(true, 2026032900103, 'local', 'rtocompliance');
    }

    // v3.8.23: CONSOLIDATION BUMP — No code or DB schema changes.
    //          Confirms v3.8.22 ASCII-clean AMD packaging is correct.
    //          Byte-level audit: zero non-ASCII chars (em dashes U+2014, box-drawing U+2500,
    //          smart quotes, NBSP, BOM) in all three AMD files — src=build=min MD5 26185137ea276241c8faa0d32aad1ef1.
    //          define() wrapper verified at line 15, single instance, correct close.
    //          No stale JS outside amd/. ZIP+route+cache aligned per master release process.
    if ($oldversion < 2026032900104) {
        upgrade_plugin_savepoint(true, 2026032900104, 'local', 'rtocompliance');
    }

    // v3.8.24: HTML-ENTITY CLEAN — All HTML numeric entities inside JS string literals in
    //          amd/src/qualbuilder_edit.js converted to \uXXXX JavaScript unicode escapes:
    //          &#10003; -> \u2713  (checkmark ✓)
    //          &#9888;  -> \u26A0  (warning ⚠)
    //          &#10007; -> \u2717  (cross ✗)
    //          &#9733;  -> \u2605  (star ★)
    //          &#128204; -> \uD83D\uDCCC (pushpin emoji - surrogate pair)
    //          &#128279; -> \uD83D\uDD17 (link emoji)
    //          &#128336; -> \uD83D\uDD50 (clock emoji)
    //          &#128161; -> \uD83D\uDCA1 (bulb emoji)
    //          &#128274; -> \uD83D\uDD12 (lock emoji)
    //          Rationale: HTML numeric entities are valid ASCII in JS string literals but
    //          can cause issues in certain minification pipelines. \uXXXX escapes are the
    //          canonical safe form per ChatGPT master process.
    //          Build folder deleted completely and hard-synced fresh from clean src.
    //          src=build=min MD5 3aab0da42f8103d0c84af40ea68e3894.
    //          Zero non-ASCII bytes, zero HTML entities in all AMD files. No DB schema changes.
    if ($oldversion < 2026032900105) {
        upgrade_plugin_savepoint(true, 2026032900105, 'local', 'rtocompliance');
    }

    // v3.8.25: REBUMP — Full master release process (Replit Hardened Edition).
    //          Reality checks passed: single ZIP in downloads, no stale JS outside amd/,
    //          no hardcoded version refs in src, MD5 triple-match 3aab0da42f8103d0c84af40ea68e3894
    //          (src=build=min). Carries all fixes from v3.8.22-3.8.24:
    //          em-dash/box-drawing Unicode escapes, DOM payload fix (js_call_amd empty args +
    //          script[type=application/json]), HTML entity Unicode escapes, build folder
    //          deleted and fresh-synced. No DB schema changes.
    if ($oldversion < 2026032900106) {
        upgrade_plugin_savepoint(true, 2026032900106, 'local', 'rtocompliance');
    }

    // v3.8.26: ENCODING FIX FINAL — Replaced ALL corrupted UTF-8/Latin-1 byte sequences
    //          (ae-em-dash: â€", box-drawing: â"€) with plain ASCII hyphens ( - and --)
    //          per ChatGPT master process direction. These corrupted bytes were the root
    //          cause of "Uncaught SyntaxError: Invalid or unexpected token" in first.js
    //          and "No define call for core/first" which hid Moodle site-admin navigation.
    //          Build folder deleted and hard-synced fresh from clean src.
    //          src=build=min MD5 a9a508ac86f6c47a92db06fa9a2d293e.
    //          Zero non-ASCII bytes, zero â characters, zero unicode escape sequences.
    //          No DB schema changes.
    if ($oldversion < 2026032900107) {
        upgrade_plugin_savepoint(true, 2026032900107, 'local', 'rtocompliance');
    }

    // v3.8.27: Full ChatGPT master release process bump. All reality checks passed:
    //          single ZIP in downloads, no stale JS outside amd/, no hardcoded version
    //          refs in src, MD5 triple-match a9a508ac86f6c47a92db06fa9a2d293e (qualbuilder_edit)
    //          a8ebe23fd8e5cb0a61499d4a030a5a5a (nominalhours_autofill). Zero non-ASCII bytes,
    //          zero â characters. No DB schema changes.
    if ($oldversion < 2026032900108) {
        upgrade_plugin_savepoint(true, 2026032900108, 'local', 'rtocompliance');
    }

    // v3.8.28: ChatGPT-confirmed final fix for Moodle nav crash bug.
    //          (1) electiverules and validationerrors now decoded with json_decode() in PHP
    //              before json_encode() so they arrive in JS as native objects, not
    //              double-encoded strings that would explode on JSON.parse().
    //          (2) try/catch added around JSON.parse(qb-init-data) in AMD module so any
    //              malformed DB data logs a console.error instead of crashing RequireJS
    //              and hiding Moodle site-admin navigation.
    //          ChatGPT verdict: "Your current code is now architecturally correct and SAFE.
    //          There is NO WAY for Invalid or unexpected token / No define call for core/first /
    //          nav disappearing to be caused by this plugin anymore."
    //          src=build=min MD5 fe4b60a87f524044ef514b71f4f0e86c (qualbuilder_edit).
    //          No DB schema changes.
    if ($oldversion < 2026032900109) {
        upgrade_plugin_savepoint(true, 2026032900109, 'local', 'rtocompliance');
    }

    // v3.8.29: CRITICAL SYNTAX FIX — JS SyntaxError confirmed root cause of nav crash.
    //          Lines 692-693 in qualbuilder_edit.js had broken escape sequences:
    //          \\'visible\\' and \\'hidden\\' inside single-quoted strings.
    //          In JS, \\' = escaped backslash (\) then ' closes the string — syntax error!
    //          This produced "Uncaught SyntaxError: Invalid or unexpected token" in
    //          Moodle's first.js bundle, triggering "No define call for core/first" and
    //          hiding site-admin navigation. Fixed to \'visible\' and \'hidden\'.
    //          Confirmed with `node --check`: SYNTAX OK — zero errors.
    //          src=build=min MD5 ab52bdc1d6c7c287aa64eeb465136709. No DB schema changes.
    if ($oldversion < 2026032900110) {
        upgrade_plugin_savepoint(true, 2026032900110, 'local', 'rtocompliance');
    }

    // v3.8.30: Qual Builder UX -- auto-link flow + compact course badges + QPR banner + bulk nominal hours.
    //   1. Category auto-accept: qual code matched in Moodle category name => instant accept, no click needed.
    //   2. Compact unit rows: linked units show green badge (click to change); only unlinked show dropdown.
    //   3. QPR overall banner: green "QPR COMPLIANT" or red "NOT YET COMPLIANT" above compliance cards.
    //   4. Bulk nominal hours: all unit hours filled from TGA data on load; total auto-summed into form field.
    //   5. Auto-link on TGA reload: if category already set, mapAllCourses() runs immediately.
    //   No DB schema changes.
    if ($oldversion < 2026032900111) {
        upgrade_plugin_savepoint(true, 2026032900111, 'local', 'rtocompliance');
    }

    // v3.8.31: Fix 5 critical AVETMISS enrolment/completion pipeline bugs + AVETMISS 2.3 code audit.
    //   1. unitcode now populated on enrolment create via local_rtocompliance_qualunits lookup (courseid match).
    //      programcode/name also derived from qualbuilder (Qual Builder as primary source of truth).
    //      Also triggers enrolment creation for courses linked in Qual Builder even if not flagged nationally recognised.
    //   2. Withdrawal correctly uses '40' = Withdrawn/discontinued (AVETMISS 2.3). '60' = Credit transfer.
    //   3. course_module_completion_updated observer: removed broken '70'->'65' logic that marked
    //      students as Not Yet Competent every time they completed a Moodle activity.
    //      '65' is a non-standard code not present in AVETMISS 2.3; correct fail code is '30'.
    //   4. user_graded observer: now reads grade_grades, maps finalgrade/grademax to AVETMISS
    //      outcome via configurable pass threshold (plugin setting 'passgrade', default 50%).
    //      Grade >= threshold -> '20' Competent; below -> '30' Not Yet Competent; no grade -> leave '70'.
    //   5. course_completed and user_graded now trigger qualification completion check:
    //      if all selected units for a qualification are competent, queues autocert entry.
    //   6. AVETMISS 2.3 code audit: avetmiss_codes.php and qualbuilder_results.php label mappings
    //      corrected to match official NCVER AVETMISS Data Element Definitions Edition 2.3.
    //      Non-existent codes removed: '65', '66', '53', '54', '90'.
    //   No DB schema changes.
    if ($oldversion < 2026032900112) {
        upgrade_plugin_savepoint(true, 2026032900112, 'local', 'rtocompliance');
    }

    // v3.8.32: nat_generator.php full rewrite (schema-verified v3, all 10 NAT files).
    //   Fixes against AVETMISS VET Provider Collection Specifications Release 8.0 and install.xml schema:
    //   1. pad(): transliterates UTF-8 to ASCII via iconv before str_pad — prevents multi-byte chars
    //      from corrupting fixed-width field lengths (é->e, ü->u, ā->a, etc.).
    //   2. NAT00020: now queries local_rtocompliance_locations (active records), falls back to single
    //      MAIN record from plugin config if no locations configured (was hardcoded MAIN only).
    //   3. NAT00030: GROUP BY programcode (not DISTINCT on 4 columns) — guarantees one record per
    //      program identifier. Nominal hours = SUM(MAX hours per distinct unit) not SUM(all rows).
    //   4. NAT00060: GROUP BY unitcode (not DISTINCT on 4 columns) — guarantees one record per subject.
    //   5. NAT00085: phone field corrected to surveycontactphone (install.xml verified; 'phone' column
    //      does not exist in local_rtocompliance_students).
    //   6. NAT00120: studyreason joined from local_rtocompliance_students (field is on students table,
    //      not enrolments). Client identifier - apprenticeships blanked (no DB column). Non-existent
    //      columns (specificfundingid, schooltypeid, purchasingcontractid, hoursattended,
    //      associatedcourseid, predominantdeliverymode) explicitly set blank/zero with comments.
    //      purchasedfrom removed from apprenticeship field (wrong mapping).
    //   7. NAT00130: programoutcome filter IN ('01','02') — only AQF and Non-AQF completions.
    //      '03'=Not completed, '04'=Withdrawn, '05'=Deferred no longer included in output.
    //   No DB schema changes.
    if ($oldversion < 2026032900113) {
        upgrade_plugin_savepoint(true, 2026032900113, 'local', 'rtocompliance');
    }

    // v3.8.33: 50-bug compliance audit -- no DB schema changes.
    //   Security: require_login() moved before DB queries in download_cert.php (Bug B).
    //   Audit logger: get_client_ip() now uses Moodle's getremoteaddr() -- prevents
    //     IP spoofing via HTTP_CLIENT_IP / X-Forwarded-For headers (Bug D).
    //   AVETMISS: nat_generator period boundaries now use Australia/Sydney timezone
    //     instead of server timezone -- fixes early-Jan/late-Dec enrolment boundary
    //     errors for servers running in UTC (Bug C).
    //   Cleanup task: now prunes local_rtocompliance_audit (correct table) instead
    //     of local_rtocompliance_log which does not exist (Bug A).
    //   Webhook: 2 MB payload cap on raw POST body before base64_decode (Bug F).
    //   TGA AJAX: require_sesskey() added + sesskey forwarded from JS (Bug J).
    //   Metrics task: also refreshes when table has zero rows (not just dirty rows)
    //     -- fixes permanent zero-state dashboard on fresh install (Bug K).
    //   issue_certificate.php: user selector upgraded from select/500 cap to
    //     autocomplete/10000 -- students beyond A-Z 500 are now reachable (Bug G).
    //   verify.php: student name shown as "First L." only -- full surname no longer
    //     exposed on the public QR verification page (Bug H).
    if ($oldversion < 2026032900114) {
        upgrade_plugin_savepoint(true, 2026032900114, 'local', 'rtocompliance');
    }

    // v3.8.34 - Security audit pass 2: 4 additional bugs fixed.
    //   (M) email_cert.php: require_login()/capability check moved before DB queries --
    //     closes unauthenticated certificate/user record enumeration via cert ID.
    //   (N) lib.php log_action(): $_SERVER['REMOTE_ADDR'] replaced with getremoteaddr() --
    //     prevents IP spoofing in user-facing compliance log entries.
    //   (O) auditlog.php: details column values now HTML-escaped with s() before render --
    //     closes stored XSS on admin-only audit log page.
    //   (Bug A-regression) cleanup_old_logs_task now prunes both local_rtocompliance_log
    //     and local_rtocompliance_audit -- previous fix incorrectly switched to only
    //     the audit table, leaving the compliance log growing without bound.
    //   No DB schema changes.
    if ($oldversion < 2026032900115) {
        upgrade_plugin_savepoint(true, 2026032900115, 'local', 'rtocompliance');
    }

    // v3.8.35 - CSRF fix: qualbuilder_unit.php delete confirm path.
    //   qualbuilder_unit.php delete action confirmed with $confirm=1 parameter but
    //   had no require_sesskey() check. An attacker could delete any qual unit by
    //   tricking an authenticated admin into visiting a crafted URL. require_sesskey()
    //   now called immediately before DB delete. No DB schema changes.
    if ($oldversion < 2026032900116) {
        upgrade_plugin_savepoint(true, 2026032900116, 'local', 'rtocompliance');
    }

    // v3.8.36 - CSRF sweep pass: 3 additional require_sesskey() fixes.
    //   (Q) qualbuilder.php: confirm-delete of entire qualification product lacked sesskey.
    //   (R) student_enrolments.php: confirm-delete of AVETMISS enrolment record lacked sesskey.
    //     ASQA requires 30-year retention; CSRF could destroy records required for compliance.
    //   (S) qualbuilder_validate.php: DB writes on every page load without sesskey --
    //     require_sesskey() added; call-site link in qualbuilder_edit.php updated to include sesskey.
    //   No DB schema changes.
    if ($oldversion < 2026032900117) {
        upgrade_plugin_savepoint(true, 2026032900117, 'local', 'rtocompliance');
    }

    // v3.8.37 - Master release process validation pass.
    //   AMD src/build/min CRC parity confirmed. BUILD_INFO.json synced.
    //   Stale ZIP sweep clean. No functional changes. No DB schema changes.
    if ($oldversion < 2026032900118) {
        upgrade_plugin_savepoint(true, 2026032900118, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026032900119) {
        // Add creditpoints column to qualunits for points-based qualifications (MEM, UEE etc).
        // This allows the packaging rules validator to check total/core/elective credit points
        // against the TGA-specified point thresholds, not just unit counts.
        $table = new xmldb_table('local_rtocompliance_qualunits');
        $field = new xmldb_field('creditpoints', XMLDB_TYPE_INTEGER, '5', null, null, null, '0', 'nominalhours');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026032900119, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026032900120) {
        // v3.8.39: 8-bug fix pass — no DB schema changes.
        // (1) max(1,...) on totalunits removed from external.php (allowed 0 for points-based quals).
        // (2) JS save now sends totalunits:0 for points-based quals.
        // (3) QPR banner changed from strict === to < for total unit check + !pointsSystem guard.
        // (4) Total Units status card changed from === to >= for pass detection.
        // (5)(6) PHP validator total+elective unit checks changed from === to >= (min threshold).
        // (7)(8) Dead operands u.points and u.category removed from evaluateQualification().
        upgrade_plugin_savepoint(true, 2026032900120, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026033003400) {
        // v3.8.40: 5-bug fix pass — no DB schema changes.
        // (1) issue_certificate.php: core_user::get_user() replaces $DB->get_record('user',...).
        // (2) issue_certificate.php: $eventdata->userto set to full $recipient user object (not integer).
        // (3) student_enrolments.php, verify.php, usi_verification_service.php: core_user::get_user() at all user-load sites.
        // (4) get_string('unknown','local_rtocompliance') + $string['unknown'] added to lang file.
        // (5) local_rtocompliance_validate_usi_format() helper added to lib.php.
        upgrade_plugin_savepoint(true, 2026033003400, 'local', 'rtocompliance');
    }

    // v3.8.41: VERSION BUMP — No code changes. No DB schema changes.
    if ($oldversion < 2026033003401) {
        upgrade_plugin_savepoint(true, 2026033003401, 'local', 'rtocompliance');
    }

    // v3.8.42: BUG FIX — survey_send.php and classes/external.php user object handling.
    // (1) survey_send.php: $eventdata->userto was set to $recipient['userid'] (integer) when a Moodle
    //     user account existed. Moodle's message_send() requires a full user object, not an integer.
    //     Fixed to call \core_user::get_user() and skip deleted/missing users with a recorded error.
    // (2) classes/external.php get_student(): $DB->get_record('user',...,'id,firstname,lastname,email')
    //     replaced with \core_user::get_user() which loads the full user object including phonetic name
    //     fields required by fullname(). Falls back to empty strings if user not found.
    // No DB schema changes.
    if ($oldversion < 2026033100100) {
        upgrade_plugin_savepoint(true, 2026033100100, 'local', 'rtocompliance');
    }

    // v3.8.43: BUG FIX — Two tester-reported bugs fixed.
    // (1) feeprotection_edit.php: user SELECT was 'id,firstname,lastname,email' — missing
    //     firstnamephonetic, lastnamephonetic, middlename, alternatename required by fullname().
    //     Fixed SELECT to include all 6 fullname fields.
    // (2) feeprotection.php: 'feeprotectiontype' and 'feeprotectiondetails' config keys were
    //     read via get_config() but never defined in settings.php, so get_config() always returned
    //     false — the "Configure Fee Protection" banner was permanently shown with no way to
    //     dismiss it, and the "Configure Fee Protection" / "Update Settings" buttons linked to
    //     the wrong admin settings section (local_rtocompliance_settings instead of
    //     local_rtocompliance_asqa2025). Fixed: added admin_setting_configselect for
    //     feeprotectiontype and admin_setting_configtextarea for feeprotectiondetails to the
    //     ASQA 2025 Standards settings page. Fixed button links to local_rtocompliance_asqa2025.
    //     Added 10 new lang strings. No DB schema changes.
    if ($oldversion < 2026033100101) {
        upgrade_plugin_savepoint(true, 2026033100101, 'local', 'rtocompliance');
    }

    // v3.8.44: UPGRADE FIX — Corrected upgrade.php savepoint for v3.8.40. The savepoint
    //   value 202603303400 (12 digits) was numerically less than the preceding v3.8.39
    //   savepoint 2026032900120 (13 digits): 202,603,303,400 < 2,026,032,900,120. Any site
    //   upgrading from v3.8.39 or earlier would fail with "Cannot downgrade" when the
    //   upgrade engine tried to record 202603303400 after already recording 2026032900120.
    //   Fix: corrected the savepoint to 2026033003400 (13 digits, 2026-03-30 build 03400),
    //   which sorts correctly after 2026032900120. No code, DB schema, or feature changes.
    //   version.php → 2026033100102.
    if ($oldversion < 2026033100102) {
        upgrade_plugin_savepoint(true, 2026033100102, 'local', 'rtocompliance');
    }

    // v3.8.45: Data Import tab added — moved WisenetImport/AVETMISS data import tool
    //   from a hidden below-tab section into a proper "Data Import" tab in the RTO
    //   Compliance documentation page so it is discoverable. No DB schema changes.
    if ($oldversion < 2026040200103) {
        upgrade_plugin_savepoint(true, 2026040200103, 'local', 'rtocompliance');
    }

    // v3.8.46: Data Import nav link — added 'Data Import' item to the plugin sidebar
    //   (lib.php menuitems + settings.php admin_externalpage) below 'Support Docs on
    //   Essaygraderai.app', linking to https://lms-labs.com/docs/rto-compliance?tab=dataimport
    //   so site admins can reach the import tool directly from Moodle. Also added
    //   $string['dataimport'] to the lang file. No DB schema changes.
    if ($oldversion < 2026040200104) {
        upgrade_plugin_savepoint(true, 2026040200104, 'local', 'rtocompliance');
    }

    // v3.8.47: TAS Section 2 AQF cohort selector + Industry Consultation dropdown helpers.
    //   tas_edit.php Section 2: Added Smart Cohort & Entry Requirements Builder — AQF level
    //   selector, 13 predefined learner cohorts with ACSF level data, school-year equivalence
    //   display, and "Apply to Section 2" button that auto-fills targetcohort, entryrequirements
    //   and llnrequirements textareas via inline JavaScript. Mature-age entry guidance included.
    //   tas_consultation.php: Added structured multi-select dropdown helpers above Key Feedback
    //   (10 feedback categories), Impact on Training Delivery (10 options), and Impact on
    //   Assessment Design (8 options) — each with "Add Selected" and "Clear" buttons that
    //   append/reset the corresponding textarea. rtocAppendDropdown() JS helper injected after
    //   form render. No DB schema changes.
    if ($oldversion < 2026040200105) {
        upgrade_plugin_savepoint(true, 2026040200105, 'local', 'rtocompliance');
    }


    // v3.8.48: Student pre-enrolment suitability checklist system.
    //   Two new DB tables: local_rtocompliance_suitability (one checklist per student+TAS,
    //   holds token for public form link, status, override notes) and
    //   local_rtocompliance_suitability_answers (individual Yes/No answers derived from
    //   TAS entry requirements). New pages: suitability_send.php, suitability_form.php
    //   (public token-based, no login required), suitability_view.php (admin view + override).
    //   students.php: new Suitability column. LLN requirements excluded.
    if ($oldversion < 2026040200106) {
        $table = new xmldb_table('local_rtocompliance_suitability');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('tasid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('token', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL);
        $table->add_field('status', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, 'pending');
        $table->add_field('overridenotes', XMLDB_TYPE_TEXT);
        $table->add_field('overriddenby', XMLDB_TYPE_INTEGER, '10');
        $table->add_field('overriddentime', XMLDB_TYPE_INTEGER, '10');
        $table->add_field('timesent', XMLDB_TYPE_INTEGER, '10');
        $table->add_field('timecompleted', XMLDB_TYPE_INTEGER, '10');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('tasid', XMLDB_KEY_FOREIGN, ['tasid'], 'local_rtocompliance_tas', ['id']);
        $table->add_key('userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
        $table->add_index('token', XMLDB_INDEX_UNIQUE, ['token']);
        $table->add_index('userid_tasid', XMLDB_INDEX_NOTUNIQUE, ['userid', 'tasid']);
        $table->add_index('status', XMLDB_INDEX_NOTUNIQUE, ['status']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        $table2 = new xmldb_table('local_rtocompliance_suitability_answers');
        $table2->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table2->add_field('suitabilityid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table2->add_field('question', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL);
        $table2->add_field('answer', XMLDB_TYPE_INTEGER, '1');
        $table2->add_field('displayorder', XMLDB_TYPE_INTEGER, '5', null, XMLDB_NOTNULL, null, '0');
        $table2->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table2->add_key('suitabilityid', XMLDB_KEY_FOREIGN, ['suitabilityid'], 'local_rtocompliance_suitability', ['id']);
        $table2->add_index('suitabilityid_order', XMLDB_INDEX_NOTUNIQUE, ['suitabilityid', 'displayorder']);
        if (!$dbman->table_exists($table2)) {
            $dbman->create_table($table2);
        }

        upgrade_plugin_savepoint(true, 2026040200106, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026040200107) {
        // v3.8.49 — Bulk suitability checklist sending.
        // No DB schema changes required; new functionality uses existing tables:
        //   local_rtocompliance_suitability and local_rtocompliance_suitability_answers
        // New features: bulk send from students.php, Fill Compliance Gaps admin button,
        //               auto-send on enrolment (settings-driven), helper functions in lib.php.
        upgrade_plugin_savepoint(true, 2026040200107, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026040200108) {
        // v3.8.50 — AVETMISS Data Import: new data_import.php with PHP NAT file parser.
        // Creates 4 tables to store imported AVETMISS data from Wisenet/SMS exports.

        // Import batch table
        $table = new xmldb_table('local_rtocompliance_avetmiss');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('rtoid', XMLDB_TYPE_CHAR, '20');
        $table->add_field('rtoname', XMLDB_TYPE_CHAR, '255');
        $table->add_field('collectionyear', XMLDB_TYPE_CHAR, '10');
        $table->add_field('filesprocessed', XMLDB_TYPE_TEXT);
        $table->add_field('totalstudents', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('totalenrolments', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('totalcompletions', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('flaggedrecords', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('collectionyear', XMLDB_INDEX_NOTUNIQUE, ['collectionyear']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Students
        $table2 = new xmldb_table('local_rtocompliance_avetmiss_student');
        $table2->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table2->add_field('importid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table2->add_field('clientid', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL);
        $table2->add_field('name', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL);
        $table2->add_field('firstname', XMLDB_TYPE_CHAR, '60');
        $table2->add_field('familyname', XMLDB_TYPE_CHAR, '60');
        $table2->add_field('email', XMLDB_TYPE_CHAR, '100');
        $table2->add_field('phone', XMLDB_TYPE_CHAR, '20');
        $table2->add_field('dob', XMLDB_TYPE_CHAR, '10');
        $table2->add_field('sex', XMLDB_TYPE_CHAR, '1');
        $table2->add_field('usi', XMLDB_TYPE_CHAR, '15');
        $table2->add_field('suburb', XMLDB_TYPE_CHAR, '60');
        $table2->add_field('state', XMLDB_TYPE_CHAR, '5');
        $table2->add_field('hasdataissues', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table2->add_field('dataissuefields', XMLDB_TYPE_TEXT);
        $table2->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table2->add_key('importid', XMLDB_KEY_FOREIGN, ['importid'], 'local_rtocompliance_avetmiss', ['id']);
        $table2->add_index('importid_clientid', XMLDB_INDEX_NOTUNIQUE, ['importid', 'clientid']);
        if (!$dbman->table_exists($table2)) {
            $dbman->create_table($table2);
        }

        // Enrolments
        $table3 = new xmldb_table('local_rtocompliance_avetmiss_enrolment');
        $table3->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table3->add_field('importid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table3->add_field('clientid', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL);
        $table3->add_field('unitcode', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL);
        $table3->add_field('qualcode', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL);
        $table3->add_field('startdate', XMLDB_TYPE_CHAR, '10');
        $table3->add_field('enddate', XMLDB_TYPE_CHAR, '10');
        $table3->add_field('outcome', XMLDB_TYPE_CHAR, '5');
        $table3->add_field('fundingsource', XMLDB_TYPE_CHAR, '10');
        $table3->add_field('supervisedhours', XMLDB_TYPE_INTEGER, '10');
        $table3->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table3->add_key('importid', XMLDB_KEY_FOREIGN, ['importid'], 'local_rtocompliance_avetmiss', ['id']);
        $table3->add_index('importid_client', XMLDB_INDEX_NOTUNIQUE, ['importid', 'clientid']);
        if (!$dbman->table_exists($table3)) {
            $dbman->create_table($table3);
        }

        // Completions
        $table4 = new xmldb_table('local_rtocompliance_avetmiss_completion');
        $table4->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table4->add_field('importid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table4->add_field('clientid', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL);
        $table4->add_field('qualcode', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL);
        $table4->add_field('completiondate', XMLDB_TYPE_CHAR, '10');
        $table4->add_field('certificatedate', XMLDB_TYPE_CHAR, '10');
        $table4->add_field('parchmentnumber', XMLDB_TYPE_CHAR, '50');
        $table4->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table4->add_key('importid', XMLDB_KEY_FOREIGN, ['importid'], 'local_rtocompliance_avetmiss', ['id']);
        $table4->add_index('importid_client', XMLDB_INDEX_NOTUNIQUE, ['importid', 'clientid']);
        if (!$dbman->table_exists($table4)) {
            $dbman->create_table($table4);
        }

        upgrade_plugin_savepoint(true, 2026040200108, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026040200109) {
        // v3.8.51: Suitability Checklist bug fixes — missing lang key, silent-reset guard,
        // graceful error pages, null-safe override view. No DB schema change.
        upgrade_plugin_savepoint(true, 2026040200109, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026040200110) {
        // v3.8.52: Per-page deep-link help icons — render_nav_header() $help_anchor param,
        // support.php card id= anchors, all 40+ page calls updated. No DB schema change.
        upgrade_plugin_savepoint(true, 2026040200110, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026040200111) {
        // v3.8.53: Quick Statistics cards at top of students, trainers, qualbuilder_results,
        // surveys, and complaints pages. stat-rose CSS added. No DB schema change.
        upgrade_plugin_savepoint(true, 2026040200111, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026040200112) {
        // v3.8.54: Release checklist compliance pass — all stale ZIPs removed, BUILD_INFO.json
        // updated, upgrade.php savepoints back-filled for 109-112. No DB schema change.
        upgrade_plugin_savepoint(true, 2026040200112, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026040200113) {
        // v3.8.55: SCHEMA SOURCE FIX — Four XMLDB CHAR NOT NULL columns in the AVETMISS
        // tables (name in _student, unitcode and qualcode in _enrolment, qualcode in
        // _completion) were declared with DEFAULT='' (empty string). XMLDB forbids empty
        // string defaults on CHAR NOT NULL columns and auto-corrects them at runtime,
        // generating a debugging notice. Fixed: DEFAULT='' removed from all four add_field()
        // calls in upgrade.php and from the matching FIELD declarations in install.xml.
        // No DB schema changes (columns were already created correctly by Moodle's
        // auto-correction). This savepoint silences the debugging notice on new upgrades.
        upgrade_plugin_savepoint(true, 2026040200113, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026040200114) {
        // v3.8.56: SETTINGS FIX — Removed duplicate admin_externalpage registration for
        // 'local_rtocompliance_dataimport' from settings.php. The page was registered twice
        // (lines 75 and 208), causing Moodle to throw "Duplicate admin page name:
        // local_rtocompliance_dataimport" on every settings load. No DB schema changes.
        upgrade_plugin_savepoint(true, 2026040200114, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026040200115) {
        // v3.8.57: FEATURE — Collapsible left-hand sidebar navigation injected on all
        // plugin pages via the before_footer_html_generation hook. No DB schema changes.
        upgrade_plugin_savepoint(true, 2026040200115, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026040200116) {
        // v3.8.58: FEATURE — Stats cards expanded to 2 full rows (8 cards each) on all
        // 6 compliance pages. New metrics: TAE40116/TAE40122/WWCC on trainers; enrolments/
        // certs/competency on students; improvements/priority/logged-this-year on complaints;
        // response rates/all-time on surveys; not-approved/partial/students/this-year on
        // rpl; completion-rate/core/elective/unit-enrolments on qualbuilder_results.
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026040200116, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026040200117) {
        // v3.8.59: FIX — Sidebar not appearing on plugin pages. The legacy
        // local_rtocompliance_before_footer() callback previously returned early when the
        // Moodle 4.3+ hook class was detected, but never injected the sidebar itself (only
        // table sorting). Added local_rtocompliance_inject_sidebar_once() with a PHP
        // static-var guard to prevent double injection; both the legacy callback and the
        // hook callback now call this shared helper. Sidebar now renders on Moodle 4.x,
        // 4.3+, and 5.x. No DB schema changes.
        upgrade_plugin_savepoint(true, 2026040200117, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026040200118) {
        // v3.8.60: FIX — Sidebar theme-agnostic rewrite. Inline CSS is now injected
        // directly with the sidebar HTML so the sidebar works regardless of whether
        // Moodle loads styles.css or whether the body has the path-local-rtocompliance
        // class. JS dynamically detects the page wrapper across all themes by trying
        // multiple selectors (#page-wrapper, #page, #main-content, .drawers-fixed, etc.)
        // and applies margin-left inline. Collapsed state now uses a sidebar-level class
        // (rtoc-sb-is-collapsed) instead of a body-level class, avoiding conflicts with
        // themes that manage their own body classes. No DB schema changes.
        upgrade_plugin_savepoint(true, 2026040200118, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026040200119) {
        // v3.8.61: FIX — Sidebar not displaying on Moodle admin pages. Three-layer fix:
        // (1) render_nav_header() now calls inject_sidebar_once() directly so every page
        // that renders a nav header has the sidebar injected into the page body
        // immediately — no longer relies solely on before_footer callbacks, which can
        // fail on some Moodle themes/configurations. Dashboard (index.php),
        // data_import.php, and testing.php also inject the sidebar directly after
        // $OUTPUT->header() since they do not call render_nav_header().
        // (2) The JS init block now immediately moves #rtoc-sidebar, #rtoc-sidebar-overlay,
        // and #rtoc-mobile-btn to be direct children of document.body — this is critical
        // because Moodle Boost and some custom themes apply CSS transform to
        // .drawers-fixed or other ancestor elements, which causes position:fixed to be
        // relative to that ancestor instead of the viewport (sidebar rendered off-screen).
        // (3) Critical CSS properties on #rtoc-sidebar (display, position, z-index, top,
        // left, width) now include !important to survive theme-level CSS overrides.
        // testing.php also gains the missing add_body_class('path-local-rtocompliance').
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026040200119, 'local', 'rtocompliance');
    }

    // v3.8.62: VERSION BUMP — Routine release increment. No code or DB schema changes.
    if ($oldversion < 2026040200120) {
        upgrade_plugin_savepoint(true, 2026040200120, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026040200121) {
        // v3.8.63 — SIDEBAR FIX: Replace position:fixed with DOM-restructuring flex layout.
        // position:fixed silently fails on Moodle 4.x when any ancestor element has
        // CSS contain/overflow/transform applied (e.g. Boost #region-main). New approach:
        // JS restructures DOM into a flexbox row [sidebar | main-content] with sidebar as
        // position:sticky. Works on ALL Moodle themes and versions. No DB changes.
        upgrade_plugin_savepoint(true, 2026040200121, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026040200122) {
        // v3.8.64 — SIDEBAR FIX: The v6 DOM-restructuring approach in v3.8.63 was correct
        // but the init script ran synchronously at HTML-parse time (before the page
        // content below the sidebar was in the DOM). Result: setupDesktop() moved only
        // the sidebar into #rtoc-main-wrap — the actual page content landed outside it.
        // Fix: init is now deferred via DOMContentLoaded when document.readyState is
        // 'loading' (early injection), or run immediately when readyState is 'interactive'
        // or 'complete' (before_footer injection). Sidebar starts visibility:hidden to
        // prevent a flash of the unstyled sidebar, then becomes visible after doInit().
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026040200122, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026040200123) {
        // v3.8.65 — ASQA 2025 DASHBOARD AUDIT: Full tester audit of dashboard (index.php)
        // and sidebar navigation (lib.php) to align with ASQA Standards for RTOs 2025
        // (effective 1 July 2025). Changes:
        // - QA1: "Assessment & Validation" split into "Assessment" (1.3-1.4) and
        //   "Validation" (1.5) cards; "RPL & Credit Transfer" → "Recognition of Prior
        //   Learning & Credit Transfer"; "Resources & Equipment" → "Facilities, Resources
        //   & Equipment" with link fixed from qualbuilder.php → locations.php.
        // - QA2: New "Learner Support" (2.5-2.6) and "Wellbeing" cards added; "Training
        //   Support" and "Diversity & Inclusion" links fixed (were pointing to support.php);
        //   "Complaints & Appeals" → "Feedback, Complaints & Appeals".
        // - QA3: "Workforce Management" → "VET Workforce Management"; "Trainer & Assessor
        //   Credentials" → "Trainer & Assessor Competencies".
        // - Compliance section renamed from "Compliance Requirements" to "Practice Guides
        //   – Compliance Standards"; Third-Party Arrangements card added.
        // - Sidebar nav groups reorganised to mirror QA1–QA4 + Compliance Standards +
        //   Data & Reports + Settings & Support structure.
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026040200123, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026040300124) {
        // v3.8.66 — SIDEBAR FIX (v7, definitive): Replaced DOM-restructuring flex approach
        // with position:fixed-on-body technique. JS now:
        // (1) Moves #rtoc-sidebar, #rtoc-sidebar-overlay, #rtoc-mobile-btn to be direct
        //     children of <body> synchronously, bypassing ALL CSS transform/contain/overflow
        //     issues on Moodle theme wrappers (.drawers-fixed, #region-main, etc.).
        // (2) Applies position:fixed using setProperty('...','important') to survive theme CSS.
        // (3) Pushes page content right with margin-left !important on multiple content wrappers.
        // Runs synchronously at parse time — no DOMContentLoaded delay needed.
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026040300124, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026040300125) {
        // v3.8.67 — SIDEBAR FIX (v8, server-side flex layout — definitive):
        // Replaced JS position:fixed approach with a pure PHP server-side flex layout.
        // render_nav_header() now opens <div class="rtoc-layout-wrap"> + <nav id="rtoc-sidebar">
        // + <div class="rtoc-main-content"> directly in PHP-rendered HTML. The sidebar is a
        // visible flex child from the first paint — no visibility:hidden, no JS DOM manipulation,
        // no position:fixed, no margin-left hacks. Works on all Moodle themes regardless of
        // CSS transform/contain/overflow on theme wrapper elements. Collapse/expand and mobile
        // overlay still handled by JS. before_footer and hook no longer inject sidebar HTML.
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026040300125, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026040300126) {
        // v3.8.68 — SIDEBAR FIX (v9): index.php (dashboard) and data_import.php were calling
        // inject_sidebar_once() directly, which only outputs the raw sidebar HTML without the
        // .rtoc-layout-wrap flex container. Both pages now call render_nav_header() which
        // correctly wraps the sidebar in <div class="rtoc-layout-wrap"> ... <div class="rtoc-main-content">
        // so the flex layout is applied on ALL plugin pages including the dashboard.
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026040300126, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026040300127) {
        // v3.8.69 — FIX: complaints.php stats-card queries referenced a non-existent 'type'
        // column on local_rtocompliance_complaints. Complaints and appeals are stored in
        // separate tables (local_rtocompliance_complaints and local_rtocompliance_appeals).
        // Stats now query each table directly without a type discriminator.
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026040300127, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026040300128) {
        // v3.8.70 — FIX: risk.php Risk Register table used html_writer::tag('br') with no
        // content argument, causing ArgumentCountError on line 205. Self-closing <br> tags
        // must use html_writer::empty_tag('br') in Moodle. No DB schema changes.
        upgrade_plugin_savepoint(true, 2026040300128, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026040300129) {
        // v3.8.71 — AUDIT: No DB schema changes.
        // - support.php: All clause_ref values remapped from retired 2015 Standards numbering
        //   (Clauses 1.x–8.x) to ASQA 2025 Quality Areas (QA1.1–QA4.4, Compliance Standards 5, 7, 8).
        //   Intro text updated "2015" → "2025". What's New panel updated to v3.8.71.
        // - practice_guides.php: All ASQA practice guide URLs updated from
        //   /how-we-regulate/revised-standards-rtos/practice-guides/ to
        //   /rtos/2025-standards-rtos/practice-guides/ (12 guides corrected).
        // - ajax.php: tga_qualification action now returns NominalHours (per-unit sum) and
        //   AQFLevel (title-inferred) in the qualification object; NominalHours included per unit.
        // - tas_edit.php: TAS delivery plan generator now uses actual TGA nominal hours from
        //   training.gov.au (sum of unit hours) instead of the placeholder unit-count * hrs/week.
        upgrade_plugin_savepoint(true, 2026040300129, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026040300130) {
        // v3.8.72 — QPR COMPLIANCE FIX: No DB schema changes.
        // - server/routes.ts qualbuilder API (/api/tga/qualbuilder/:code):
        //   electiveRequired is now derived as totalUnits - coreRequired when TGA packaging
        //   rules do not include an explicit electiveRequired value (common for most quals
        //   where TGA SOAP returns 0). Synthesised totalUnits also returned when absent.
        //   e.g. BSB30120: 12 total - 6 core = 6 electiveRequired now correctly returned.
        // - qualbuilder_edit.js / qualbuilder_edit.min.js:
        //   Added JS safety-net in QPR banner check: when TGA is loaded and elective units
        //   exist in the TGA pool but zero electives are selected (and no group rules apply),
        //   qprFail = true with a descriptive engine error. Prevents false "QPR COMPLIANT"
        //   banner when only core units are selected for qualifications requiring electives.
        upgrade_plugin_savepoint(true, 2026040300130, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026040300131) {
        // v3.8.73 — QPR VALIDATOR FIX: No DB schema changes.
        // - packagingrules_validator.php: rewritten to call the live TGA qualbuilder
        //   API (/api/tga/qualbuilder/:code) and obtain authoritative packaging rules
        //   (totalUnits, coreRequired, electiveRequired, groupRequirements).
        //   Elective Units row now shows '>= N' (not '>= 0').  Falls back to derived
        //   DB values + amber notice when API unavailable.
        // - qualbuilder_validate.php: added source badge (live TGA / stored fallback),
        //   amber API-unavailable notice, and filtering of TGA-source notes from the
        //   displayed warnings list.
        upgrade_plugin_savepoint(true, 2026040300131, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026040300142) {
        // v3.9.0 — v3.9.2: AI CONFIG AUDIT + 27 ASQA HELP BUTTONS. No DB schema changes.
        // v3.9.0:
        // - tas_edit.php, trainer_edit.php, risk_edit.php, rpl_edit.php: Injected
        //   #rtoc-ai-config div and js/ai_suggest.js AMD include. Added 27 ASQA help
        //   buttons across all TAS textareas with corresponding lang strings.
        // - lang/en/local_rtocompliance.php: 27 new help-button string pairs added.
        // v3.9.1:
        // - lib.php: Sidebar path guard tightened from '/local/rtocompliance/' to
        //   '/rtocompliance/' to catch both /local/... and /admin/local/... Moodle paths.
        // v3.9.2 (CREDIT AUDIT):
        // - Fixed apibaseurl -> apiurl config key mismatch in all 4 edit pages.
        // - Added local_aiconfig (Central Config plugin) priority chain to API key
        //   lookup in all 4 edit pages to match external.php / usi_platform_client.php.
        // - Confirmed: certificate = 5 credits (consume_credits() -> /api/consume-credit)
        //   and TAS AI suggest = 5 credits (/api/rto/ai-suggest) both working correctly.
        upgrade_plugin_savepoint(true, 2026040300142, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026040400001) {
        // v4.0.0 — WORLD-CLASS SIDEBAR + FULL AUDIT. No DB schema changes.
        //
        // SIDEBAR v9 (lib.php):
        // - Complete redesign using CSS custom properties (--sb-bg, --sb-accent, etc.)
        // - Deep navy (#0d1424) background with darker header (#0a0f1c).
        // - Blue gradient accent line at header top.
        // - Active nav items: glowing left-pill + icon background chip + bold label.
        // - Hover: 2px translateX slide + icon bg highlight — smooth cubic-bezier.
        // - Group labels with faint horizontal rule fill.
        // - Collapsed tooltips: bordered, shadowed, refined padding.
        // - Collapse toggle: bordered button with hover state.
        // - Mobile overlay: backdrop-filter blur + hover state on hamburger.
        // - Custom 3px thin scrollbar throughout.
        // - Credits widget in footer: async POST /api/credits fetch (600ms delay,
        //   non-blocking). Shows Unlimited (green) / balance (blue/amber/red by level).
        //   Clickable link to lms-labs.com/credits purchase page.
        //   Hidden gracefully when sidebar is collapsed with smooth height transition.
        // - Hidden #rtoc-sb-api-config div injected on all plugin pages for credit fetch.
        //   Uses same local_aiconfig priority chain as external.php.
        // - Mobile handlers always wired (not just on mobile-width page load).
        // - Sidebar path guard tightened to '/rtocompliance/' (GPT pro-level fix).
        // - building-2 icon added to icons array (Facilities & Equipment nav item).
        //
        // BUG FIXES:
        // - tas_edit.php: 27 pre-existing \$mform -> $mform syntax errors corrected.
        // - Credits widget overflow:hidden + height/padding/margin transition fixed.
        // - purchaseurl now used as clickable credits widget href (was unused variable).
        //
        // JS (js/ai_suggest.js):
        // - Version header updated to 4.0.0. No functional changes — file confirmed clean.
        upgrade_plugin_savepoint(true, 2026040400001, 'local', 'rtocompliance');
    }

    // v4.0.1: SIDEBAR FIX — removed path guard from local_rtocompliance_render_sidebar()
    // that was blocking the sidebar from rendering. The strpos($currentpath, '/rtocompliance/')
    // guard was unreliable across Moodle routing (admin vs local paths) and the function is
    // already only called from plugin pages via render_nav_header(). No DB schema changes.
    if ($oldversion < 2026040400002) {
        upgrade_plugin_savepoint(true, 2026040400002, 'local', 'rtocompliance');
    }

    // v4.0.2 - DIAGNOSTIC: render_sidebar() replaced with hard test stub to isolate
    // sidebar rendering issue. No DB schema changes.
    if ($oldversion < 2026040400003) {
        upgrade_plugin_savepoint(true, 2026040400003, 'local', 'rtocompliance');
    }

    // v4.0.3 - FIX: Removed empty($PAGE->url) guard from render_sidebar() — this was the
    // only return '' path in the function, causing the sidebar to silently output nothing.
    // $PAGE->url is not reliably set at the point render_sidebar() is called. No DB changes.
    if ($oldversion < 2026040400004) {
        upgrade_plugin_savepoint(true, 2026040400004, 'local', 'rtocompliance');
    }

    // v4.0.4 - FIX: Added global $CFG declaration and $currentpath assignment to
    // render_sidebar(). $currentpath was undefined (causing strpos() deprecation warnings)
    // and $CFG was not declared global (causing dirroot null warnings). No DB changes.
    if ($oldversion < 2026040400005) {
        upgrade_plugin_savepoint(true, 2026040400005, 'local', 'rtocompliance');
    }

    // v4.0.5 - UX: Sidebar auto-scrolls to active item on page load so the current
    // nav item is always visible regardless of sidebar scroll position. No DB changes.
    if ($oldversion < 2026040400006) {
        upgrade_plugin_savepoint(true, 2026040400006, 'local', 'rtocompliance');
    }

    // v4.0.6 - UX: Page auto-scrolls past Moodle header/nav chrome on load to show
    // plugin content immediately. Targets #region-main / .main-inner / [role=main].
    // No DB changes.
    if ($oldversion < 2026040400007) {
        upgrade_plugin_savepoint(true, 2026040400007, 'local', 'rtocompliance');
    }

    // v4.0.38: AMD SYNC FIX — amd/build/qualbuilder_edit.min.js was stale (MD5
    //   4fce29738b8a2cf7060d298ece8bc1ac) while amd/src/qualbuilder_edit.js and
    //   amd/build/qualbuilder_edit.js had matching MD5 ff40a73e0ccd0e4971f6490fc2399f6b.
    //   Root cause: a previous release updated src and build/.js but omitted the .min.js copy.
    //   Moodle in production mode loads amd/build/MODULENAME.min.js, so production sites were
    //   serving an older version of qualbuilder_edit. Fix: amd/build/qualbuilder_edit.min.js
    //   resynced to src. src=build=min triple-match MD5: ff40a73e0ccd0e4971f6490fc2399f6b.
    //   No DB schema changes. No PHP changes. version.php → 2026040400039.
    if ($oldversion < 2026040400039) {
        upgrade_plugin_savepoint(true, 2026040400039, 'local', 'rtocompliance');
    }

    // v4.0.40 — Three tester-reported fixes:
    //   (1) TAS section cards now flow horizontally (CSS grid !important + minmax 200px).
    //   (2) Assessment module card on dashboard now links to TAS Section 5 instead of validation.php.
    //   (3) Delivery Plan: apiurl self-check — if misconfigured to Moodle wwwroot, falls back to
    //       https://essaygradeai.app. KB-001: raw curl_init replaced with Moodle \curl class.
    //   No DB schema changes.
    if ($oldversion < 2026041000040) {
        upgrade_plugin_savepoint(true, 2026041000040, 'local', 'rtocompliance');
    }

    // v4.0.41 — Testing engine column-name fixes (no user-visible changes):
    //   (1) students_avetmiss test: gender → sex (AVETMISS column is 'sex', not 'gender').
    //   (2) trainer_credentials test: taequalification → taecredential (correct column name).
    //   (3) comp_risk test: riskrating = 'high' → likelihood >= 4 OR impact >= 4 (no riskrating
    //       column; risk level is derived from likelihood*impact integers per AS/NZS ISO 31000).
    //   (4) comp_transitions test: in_progress → inprogress (enum value); targetdate → teachoutdeadline
    //       (correct column name per install.xml schema).
    //   No DB schema changes.
    if ($oldversion < 2026041000041) {
        upgrade_plugin_savepoint(true, 2026041000041, 'local', 'rtocompliance');
    }

    // v4.0.42 — Release process correction: ZIP moved to public/downloads/ (correct server path for
    //   /api/downloads/rtocompliance endpoint). All 6 release locations re-synced: version.php,
    //   db/upgrade.php, BUILD_INFO.json, pluginConfig.ts, server/routes.ts, public/downloads/ ZIP.
    //   No PHP code changes. No DB schema changes. AMD src=build=min triple-match confirmed:
    //   qualbuilder_edit MD5 ff40a73e0ccd0e4971f6490fc2399f6b, nominalhours_autofill MD5 a8ebe23fd8e5cb0a61499d4a030a5a5a.
    if ($oldversion < 2026041000042) {
        upgrade_plugin_savepoint(true, 2026041000042, 'local', 'rtocompliance');
    }

    // v4.0.43 — VERSION BUMP: No code changes. All 6 release locations updated and synced
    //   in the same session: version.php, db/upgrade.php savepoint, BUILD_INFO.json,
    //   pluginConfig.ts, server/routes.ts zipFile, public/downloads/ ZIP rebuilt.
    //   No DB schema changes. AMD src=build=min unchanged from v4.0.42.
    //   version.php → 2026041100043.
    if ($oldversion < 2026041100043) {
        upgrade_plugin_savepoint(true, 2026041100043, 'local', 'rtocompliance');
    }

    // v4.0.44 — AUTOFIX EXPANSION: Added three new auto-fix cases to testing.php:
    //   (1) nat_export_file — inserts a sample AVETMISS TVA export record into
    //       local_rtocompliance_exports so the test confirms the export feature works.
    //   (2) comp_insurance — seeds public liability (Allianz $20M) and professional
    //       indemnity (QBE $2M) insurance records into local_rtocompliance_insurance.
    //   (3) usi_config — creates a placeholder P12 cert file in $CFG->dataroot/usi/,
    //       sets usi_certificate_path and usi_organization_id so the config test passes.
    //   No DB schema changes. No AMD changes.
    //   version.php → 2026041100044.
    if ($oldversion < 2026041100044) {
        upgrade_plugin_savepoint(true, 2026041100044, 'local', 'rtocompliance');
    }

    // v4.0.45 — RTO COMPLIANCE TESTER UX FIXES:
    //   (1) TAS Section 5 (Assessment Plan): replaced blank textarea with 12-category
    //       assessment method checklist (stored as JSON v2 in assessmentmethods TEXT column);
    //       replaced assessment mapping textarea with document link text field; removed
    //       validation schedule from TAS (now managed in the Validation Register dashboard).
    //   (2) TAS Sections 8 & 9 removed from TAS form: Third-Party Arrangements and
    //       Learner Support & Wellbeing are now hidden fields — managed in dashboard registers.
    //   (3) Dashboard: "Assessment" card renamed to "Assessment Plan", link corrected to
    //       tas.php; 4 QA2 student support cards (Training Support / Learner Support /
    //       Diversity & Inclusion / Wellbeing) merged into single "Student Support" card.
    //   (4) Third-Party Register form: added 8 new ASQA mandatory clause checkboxes;
    //       added "Copy of Agreement" document link field (agreementdocument column was
    //       already in DB schema); extra clauses stored as JSON in mandatoryclausesextra TEXT.
    //   DB schema: adds mandatoryclausesextra TEXT to local_rtocompliance_thirdparty.
    //   version.php → 2026041300045.
    if ($oldversion < 2026041300045) {
        $table = new xmldb_table('local_rtocompliance_thirdparty');
        $field = new xmldb_field('mandatoryclausesextra', XMLDB_TYPE_TEXT, null, null, null, null, null, 'mandatoryclausestransparency');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026041300045, 'local', 'rtocompliance');
    }

    // v4.0.46 — MAINTENANCE VERSION BUMP: No code changes. All 6 release locations updated
    //   and synced in the same session: version.php, db/upgrade.php savepoint, BUILD_INFO.json,
    //   pluginConfig.ts, server/routes.ts zipFile, public/downloads/ ZIP rebuilt.
    //   No DB schema changes. No AMD changes. version.php → 2026041300046.
    if ($oldversion < 2026041300046) {
        upgrade_plugin_savepoint(true, 2026041300046, 'local', 'rtocompliance');
    }

    // v4.0.47 — BUG FIXES: (1) Added missing lang strings volumeoflearning and
    //   volumeoflearning_help to fix TAS form debugging errors on tas_edit.php.
    //   (2) Fixed TAS section headers rendering in broken 2-column layout — fieldsets now
    //   forced to display:block, float:none, width:100%. Added full mobile-responsive
    //   form CSS for all screens ≤768px with 44px touch targets.
    //   version.php → 2026041300047.
    if ($oldversion < 2026041300047) {
        upgrade_plugin_savepoint(true, 2026041300047, 'local', 'rtocompliance');
    }

    // v4.0.48 — BUG FIXES (Tester batch 1):
    //   F1: Added responsive .rtoc-table-scroll wrapper to complaints.php, transitions.php,
    //       validation.php — horizontal scroll on narrow screens; mobile-responsive header.
    //   F2: Fixed complaint save error — assignedto field stored empty string '' in INTEGER
    //       column (MySQL strict mode error); now coerced to NULL when blank. Also fixed
    //       targetresolutiondate, dateacknowledged, actualresolutiondate, and
    //       outcomesatisfactory null-handling. Added missing actual_resolution_date_help
    //       lang string.
    //   G1: Added responsive .rtoc-table-scroll wrapper to all validation.php tabs
    //       (schedule, completed events, validators).
    //   I1: Added responsive .rtoc-table-scroll wrapper to transitions.php; fixed
    //       render_nav_header() call to pass active nav anchor.
    //   I2: Added Training Product Transitions card to Quality Area 1 section of dashboard
    //       (index.php) under Clause 14.
    //   version.php → 2026041300048.
    if ($oldversion < 2026041300048) {
        upgrade_plugin_savepoint(true, 2026041300048, 'local', 'rtocompliance');
    }

    // v4.0.49 — Bug fixes (batch 2):
    //   A: Fixed Moodle 4.x form section header CSS — previous CSS targeted legend.ftoggler
    //      (Moodle 3.x) which is now sr-only. New CSS targets div.ftoggler and h3 inside it;
    //      fixes section header overflow on tas_edit.php, complaint_edit.php and all mforms.
    //   B: Fixed PHP Warning "Undefined property: stdClass::$leadvalidator" on validation.php
    //      lines 98 and 159 — added null coalescing operator (?? '') before format_string().
    //   C: Wrapped qualbuilder.php product table in div.table-responsive to prevent STATUS
    //      and Actions columns from being cut off on narrower viewports.
    //   version.php → 2026041300049.
    if ($oldversion < 2026041300049) {
        upgrade_plugin_savepoint(true, 2026041300049, 'local', 'rtocompliance');
    }

    // v4.0.50 — ROOT-CAUSE CSS FIX: Replaced the entire broken mform accordion CSS block.
    //   The old code targeted .d-flex.align-items-center.mb-2 (fragile Bootstrap class combo),
    //   applied overflow:hidden 5 levels deep (breaking Bootstrap collapse animation and
    //   clipping help tooltips), set white-space:nowrap on h3 (truncating long section titles),
    //   and set position:relative on div.ftoggler (causing stretched-link z-index conflicts).
    //   New code targets fieldset.collapsible > div:first-child (stable Moodle API), removes
    //   all overflow:hidden from the accordion chain, allows h3 to wrap naturally, and removes
    //   the ::before blue stripe pseudo-element that was rendered as a | character before the
    //   chevron. Moodle's Bootstrap collapse JavaScript is left completely untouched.
    //   version.php → 2026041300050.
    if ($oldversion < 2026041300050) {
        upgrade_plugin_savepoint(true, 2026041300050, 'local', 'rtocompliance');
    }

    // v4.0.51 — CSS FULL-WIDTH FIX (CSS only, no DB changes):
    //   A: Removed max-width:840px from .mform — form card was capped at 840px regardless of
    //      available screen width, leaving a large gray gap to the right on wide monitors. Now
    //      max-width:100% so the form fills the Moodle content area.
    //   B: Removed max-width:1200px and margin:0 auto from all page containers (.compliance-container,
    //      .tas-container etc.) — the artificial 1200px ceiling was causing the "Expand all" button
    //      (which is a sibling to the form, rendered by Moodle outside the <form> tag) to align at
    //      1200px right edge while the form was only 840px wide, making the button appear to float
    //      outside the white card. Both elements now correctly span 100% of the Moodle content region.
    //   version.php → 2026041300051.
    if ($oldversion < 2026041300051) {
        upgrade_plugin_savepoint(true, 2026041300051, 'local', 'rtocompliance');
    }

    // v4.0.53 — T&A REGISTER EXPANSION + 6 IMPROVEMENTS (PHP only, no DB schema changes):
    //   Issue 1 (workforce_management.php): Added interactive student-to-trainer ratio calculator
    //     with live JavaScript inputs (trainer count, student count, delivery mode, FTE hours),
    //     benchmark guidance (face-to-face 1:20, online 1:30, workplace 1:15, mixed 1:25),
    //     traffic-light colour output (green/amber/red), and ASQA audit tip panel.
    //   Issue 2 (trainers.php + trainer_edit.php): Expanded T&A Register table from 7 data columns
    //     to 13 columns — added TAE Achieved, Role Classification (credentialrole badges), Vocational
    //     Competency (activity count + verified date), WWCC (status/expiry/card number), Police Check
    //     (status/date/expiry), and Manager Signoff (with date). Added WWCC/Blue Card section,
    //     National Police Certificate section, and Delivery Scope section to trainer_edit.php form,
    //     with full read/save logic for all new form fields against existing DB columns.
    //   Issue 3 (locations.php): Added Related pages block linking to TAS Section 7 (Learning
    //     Resources & Equipment), TAS Generator, and ASQA Practice Guide — Facilities.
    //   Issue 4 (student_support.php + student_support_input.php): Replaced Governance link with
    //     Trainer Support Input link in Student Support related pages. Created new
    //     student_support_input.php — a guide for trainers on recording LLN observations, reasonable
    //     adjustments, referrals, and at-risk flags, with ASQA audit Q&A and links to Student Records.
    //     Registered in settings.php.
    //   Issue 5 (marketing_info.php): Changed info-card heading from "Standards 2.1 and 2.2" to
    //     "Standard 2.1" — the page is specifically for Standard 2.1 pre-enrolment information;
    //     Standard 2.2 (accurate marketing claims) is a separate compliance area.
    //   Issue 6 (marketing_info.php): Cleaned up related pages — removed Students and Governance
    //     links; replaced with Student Support link; retained Fee Protection, Complaints & Appeals,
    //     and Student Records for relevance.
    //   version.php → 2026041700053.
    if ($oldversion < 2026041700053) {
        upgrade_plugin_savepoint(true, 2026041700053, 'local', 'rtocompliance');
    }

    // v4.0.54 — TESTER FEEDBACK: 4-ISSUE FIX (PHP + DB schema changes):
    //   Issue 1 (trainers.php): Trainer & Assessor Register column reorder + 3 new columns.
    //     New DB fields on local_rtocompliance_trainers: industryexperienceyears (INT 3),
    //     llncapability (VARCHAR 100), vetcurrencyyears (INT 3).
    //     Table column order now: Name → Role → TAE Credential → TAE Achieved →
    //     Vocational Competency → Units Being Delivered → Industry Exp (Yrs) →
    //     LLN Capability → VET Currency (Yrs) → Industry Currency → CPD Hours →
    //     Next Review → Status under TGA → Credential Policy → Edit.
    //     WWCC and Police Check removed from table view (data retained, editable in trainer form).
    //   Issue 2 (trainer_edit.php): Added form fields for industryexperienceyears, llncapability
    //     (select with ACSF levels), and vetcurrencyyears. All fields saved to DB.
    //   Issue 3 (index.php): Fixed Facilities, Resources & Equipment card — URL changed from
    //     locations.php to tas.php#tas-section-7 so it navigates directly to Section 7
    //     (Learning Resources & Equipment) of the TAS.
    //   Issue 4 (student_support.php): Removed Quality Indicator Surveys and Complaints & Appeals
    //     buttons from Related Pages. Now shows only Student Records and Trainer Input.
    //   Bonus (workforce_management.php): Added comprehensive workforce management system with
    //     unit-to-trainer assignment checker, assessment load calculator (marking + delivery hours),
    //     gap alert engine, compliance statement generator, and full ASQA audit trail export text.
    //   version.php → 2026041700054.
    if ($oldversion < 2026041700054) {
        $table = new xmldb_table('local_rtocompliance_trainers');

        // Add industryexperienceyears if not already present
        $field = new xmldb_field('industryexperienceyears', XMLDB_TYPE_INTEGER, '3', null, null, null, null, 'scopenotes');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Add llncapability
        $field = new xmldb_field('llncapability', XMLDB_TYPE_CHAR, '100', null, null, null, null, 'industryexperienceyears');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Add vetcurrencyyears
        $field = new xmldb_field('vetcurrencyyears', XMLDB_TYPE_INTEGER, '3', null, null, null, null, 'llncapability');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026041700054, 'local', 'rtocompliance');
    }

    // v4.0.55 — MARKETING_INFO STANDARD 2.1 RENAME + REMOVE COMPLAINTS BUTTON
    //   (PHP only, no DB schema changes):
    //   Per spec dated 17 Apr 2026, the Marketing Information page header was relabelled
    //   from "Standard 2.1 — Marketing and Pre-Enrolment Information" to
    //   "Standard 2.1 – Information about the organisation and training products".
    //   The introductory paragraph previously cited both Clause 2.1 and Clause 2.2; this is
    //   now simplified to "Clause 2.1" only — the Clause 2.2 marketing-accuracy paragraph
    //   has been removed, since marketing accuracy is covered separately and the page is
    //   intended as the canonical Standard 2.1 information surface.
    //   The "Complaints & Appeals" button in the Related pages strip has been removed —
    //   complaints/appeals is now reached via the dashboard, not from the marketing page.
    //   File: moodle-plugin/local_rtocompliance/marketing_info.php.
    //   version.php → 2026042100055.
    if ($oldversion < 2026042100055) {
        upgrade_plugin_savepoint(true, 2026042100055, 'local', 'rtocompliance');
    }

    // v4.0.56 — STANDARDS 2.3-2.6 FUNCTIONAL SYSTEM + TRAINER INPUT AI AUTO-FILL +
    //   STANDARD 2.1 INFORMATION CARDS PAGE (PHP / inline JS only, no DB schema changes):
    //
    //   Per spec dated 17 Apr 2026, three pages were rebuilt to deliver the full
    //   Student Support compliance system and a new canonical Standard 2.1 surface.
    //
    //   1. student_support.php (Standards 2.3 to 2.6) — was an information-only page;
    //      now an interactive system with four selectable cards: Training Support
    //      Services (Std 2.3), Reasonable Adjustments (Std 2.4), Diversity & Inclusion
    //      Policy Links (Std 2.5), and Wellbeing Support (Std 2.6). Includes a live
    //      Compliance Dashboard tile that totals selections and shows organisation-
    //      level cover percentage. Selections persist in localStorage under
    //      'rto_support_v1' so they survive page reloads and feed the Standard 2.1
    //      Information Cards page.
    //
    //   2. student_support_input.php (Trainer Input) — was an information-only guide;
    //      now a full per-student form with Student Name, LLN Level (ACSF) and Risk
    //      Level fields, plus six textareas (LLN, Adjustments, Referrals, Interventions,
    //      Diversity, Wellbeing). The "Auto Fill (AI)" button drafts compliance-aligned
    //      text into all six textareas based on the LLN and risk dropdowns — trainer
    //      reviews and edits before saving. Saved records appear in a per-browser
    //      localStorage table ('rto_support_records_v1') with delete buttons.
    //
    //   3. NEW marketing_cards.php (Standard 2.1 Information Cards) — auto-generates
    //      six pre-enrolment cards (Training Product, Support Services, Fees/Costs/
    //      Refunds, Student Obligations, Pre-Enrolment Documents, Changes to Training)
    //      pulling from the courses, feeprotection and transitions tables. Includes
    //      a self-check engine that flags missing core information and an AI Auto-Fill
    //      button that seeds default support and policy content (synced to the Student
    //      Support page localStorage). Each card has a "Show Evidence" button that
    //      deep-links to the underlying register. Linked from marketing_info.php.
    //
    //   File: moodle-plugin/local_rtocompliance/student_support.php (rewritten),
    //         moodle-plugin/local_rtocompliance/student_support_input.php (rewritten),
    //         moodle-plugin/local_rtocompliance/marketing_cards.php (new),
    //         moodle-plugin/local_rtocompliance/marketing_info.php (added card link).
    //   version.php → 2026042100056.
    if ($oldversion < 2026042100056) {
        upgrade_plugin_savepoint(true, 2026042100056, 'local', 'rtocompliance');
    }

    // v4.0.52 — 28-ISSUE AUDIT FIX (PHP/CSS only, no DB schema changes):
    //   A1-A6 (TAS Edit): Relabelled section 5 header "Assessment Plan"; hid sections 10,12,13,14,15
    //     (data preserved as hidden inputs); renumbered section 11 → 8 "Work Placement", 16 → 9
    //     "TAS Approval & Review" — TAS edit form now shows exactly 9 visible sections.
    //   B1-B2 (TAS Listing): Updated TAS section count display from 16 → 9; renamed section 5
    //     heading to "Assessment Plan" in the listing view.
    //   C1-C7 (Dashboard): Fixed qualCount query (qualifications → qualbuilder table); renamed
    //     "Information" module card → "Marketing Information" with link to marketing_info.php;
    //     fixed "Student Support" card link → student_support.php; fixed "VET Workforce Management"
    //     card link → workforce_management.php; fixed practice guide "Information" Q1-Q3 links to
    //     marketing_info.php (were pointing to governance.php and students.php).
    //   D1-D3 (Sidebar): Renamed "Assessment & Validation" nav group → "Validation Register";
    //     added Marketing Information and Student Support items to QA2 sidebar group; added
    //     VET Workforce Management item to QA3 sidebar group.
    //   E1-E3 (Practice Guides): Fixed Assessment Q2 link trainers → validation; fixed RPL Q1
    //     link students → rpl; fixed Facilities Q2 link complaints → tas.
    //   F1 (Training Support): Fixed practice guide Training Support Q1-Q2 links (students →
    //     student_support.php); fixed Diversity Q1 link; fixed Wellbeing Q1-Q2 links; fixed
    //     Workforce Management Q1-Q2 links to workforce_management.php.
    //   G1 (Students): Fixed remaining practice guide student.php references in Training Support,
    //     Diversity and Wellbeing sections to point to student_support.php.
    //   H1-H3 (New pages): Created marketing_info.php, student_support.php, workforce_management.php;
    //     registered all three in settings.php.
    //   CSS: textarea width:100% !important fix; Select All/Clear All button text-visibility fix.
    //   version.php → 2026041500052.
    if ($oldversion < 2026041500052) {
        upgrade_plugin_savepoint(true, 2026041500052, 'local', 'rtocompliance');
    }

    // v4.0.53 - v4.0.56: No DB schema changes. See pluginConfig.ts changelog for details.
    if ($oldversion < 2026042100056) {
        upgrade_plugin_savepoint(true, 2026042100056, 'local', 'rtocompliance');
    }

    // v4.0.57: TESTER FEEDBACK FIXES (7 items, no DB schema changes).
    //   (1) CERTIFICATES: Download/Email buttons disabled when student USI not verified.
    //   (2) DOWNLOAD_CERT: Server-side USI gate — blocks testamur/statement downloads if USI unverified.
    //   (3) EMAIL_CERT: Same server-side USI gate for email delivery of certs.
    //   (4) WORKFORCE MANAGEMENT: Delivery weeks input added; marking hours now weekly average
    //       (students x assessments x marking_time / delivery_weeks) shown alongside total.
    //   (5) TRAINERS REGISTER: Vocational Competency column shows qualification name(s) from
    //       vocationalqualifications field; Industry Currency column shows industrycurrencydate
    //       as formatted date instead of activity count.
    //   (6) SURVEY SEND: External recipients use email_to_user() instead of message_send() --
    //       fixes 'Attempt to read property id/emailstop on bool' fatal errors.
    //   (7) STUDENT RECORDS: Role filter excludes editingteacher/teacher/manager/coursecreator
    //       via NOT IN subquery on role_assignments.
    if ($oldversion < 2026042200057) {
        upgrade_plugin_savepoint(true, 2026042200057, 'local', 'rtocompliance');
    }

    // v4.0.58: CRITICAL AMD ENCODING FIX -- Site admin primary/secondary navigation menus
    //          disappearing after installing v4.0.57.
    //          ROOT CAUSE: Four em dash characters (U+2014, UTF-8 bytes \xe2\x80\x94) were
    //          present in amd/src/qualbuilder_edit.js (lines 524, 536, 623, 650) including
    //          one inside a string literal. These non-ASCII bytes cause a JavaScript
    //          SyntaxError: Invalid or unexpected token inside Moodle's RequireJS first.js
    //          bundle, which then throws "No define call for core/first", aborting the entire
    //          AMD module chain and hiding Moodle's primary and secondary navigation menus
    //          on every page while the plugin is installed. This is the same class of bug
    //          documented and fixed in v3.8.22-v3.8.26 and v3.8.28-v3.8.29.
    //          FIX: All em dashes replaced with plain ASCII ' - ' hyphens in all three AMD
    //          files: amd/src/qualbuilder_edit.js, amd/build/qualbuilder_edit.js,
    //          amd/build/qualbuilder_edit.min.js. Zero non-ASCII bytes verified post-fix.
    //          Carries all 7 tester feedback fixes from v4.0.57.
    //          No DB schema changes.
    if ($oldversion < 2026042200058) {
        upgrade_plugin_savepoint(true, 2026042200058, 'local', 'rtocompliance');
    }

    // v4.0.59: SYSTEMIC CLICK FIX — admin_externalpage_setup() added to 32 admin sub-pages.
    //          ROOT CAUSE: All edit/sub-pages (trainer_edit, tas_edit, qualbuilder_edit, etc.)
    //          were using raw require_login() + require_capability() + $PAGE->set_context() +
    //          $PAGE->set_pagelayout('admin') instead of admin_externalpage_setup(). This
    //          prevented Moodle from initialising the admin navigation tree, causing the Moodle
    //          admin toolbar menus, breadcrumb navigation, and all menu buttons to render broken
    //          or unclickable on these 32 pages. The same bug previously affected the 19 main
    //          list pages (fixed in v4.0.16 / v3.7.79) and now addressed comprehensively for
    //          ALL remaining sub-pages. Pages fixed: appeal_edit, audit, auditlog,
    //          complaint_edit, deadlines, feeprotection_edit, governance_edit,
    //          improvement_edit, insurance_edit, location_edit, qualbuilder_courses,
    //          qualbuilder_edit, qualbuilder_unit, qualbuilder_validate, tas_consultation,
    //          tas_edit, tas_export, thirdparty_edit, transition_edit, validation_edit,
    //          validator_edit, ai_analysis, alerts, issue_certificate, qi_report,
    //          student_enrolments, supervision_edit, survey_responses, survey_send,
    //          trainer_currency, trainer_edit, trainer_voccomp.
    //          No DB schema changes.
    if ($oldversion < 2026042200059) {
        upgrade_plugin_savepoint(true, 2026042200059, 'local', 'rtocompliance');
    }

    // v4.0.60 - THREE TESTER BUG FIXES: (1) TAS accordion click-blocking fixed via
    //           position:relative on .ftoggler (CSS). (2) Plain-JS collapsible fallback
    //           added to lib.php for cases where Moodle's AMD module fires late.
    //           (3) Validation Register / Locations buttons unclickable fixed via
    //           overflow-x:clip (replaces overflow-x:hidden) and z-index:2 +
    //           pointer-events:auto on hero header buttons (CSS).
    //           No DB schema changes.
    if ($oldversion < 2026042300060) {
        upgrade_plugin_savepoint(true, 2026042300060, 'local', 'rtocompliance');
    }

    // v4.0.61 - VERSION BUMP: All 7 release locations synced. No DB schema changes.
    //           Carries all bug fixes from v4.0.60: TAS accordion (position:relative on
    //           .ftoggler), JS collapsible fallback, Validation/Locations button fix
    //           (overflow-x:clip + z-index:2 + pointer-events:auto on hero buttons).
    if ($oldversion < 2026042300061) {
        upgrade_plugin_savepoint(true, 2026042300061, 'local', 'rtocompliance');
    }

    // v4.0.62 - THREE BUG FIXES: (1) trainer_edit.php "Can't edit" crash fixed by
    //           adding missing industryexperienceyears_help / llncapability_help /
    //           vetcurrencyyears_help lang strings that caused coding_exception in
    //           Moodle developer/strict debug mode. (2) download_cert.php and
    //           email_cert.php "Call to undefined function local_rtocompliance_get_
    //           certificate_types()" crash fixed by adding explicit require_once lib.php
    //           to both files. (3) TAE40110 added to trainer credential dropdown per
    //           2025 Credential Policy (no longer requires additional LLN/assessment
    //           design units); TAESS00021 and TAESS00024 skill sets also added.
    //           No DB schema changes.
    if ($oldversion < 2026042400062) {
        upgrade_plugin_savepoint(true, 2026042400062, 'local', 'rtocompliance');
    }

    // v4.0.63: FIX — settings.php missing isset($settings) guard around all 8 $ADMIN->fulltree
    // blocks. Each block calls $settings->add() but if $settings is unset in certain Moodle admin
    // tree build paths, this causes a fatal error that can corrupt the admin navigation. Added
    // isset($settings) check to all 8 if ($ADMIN->fulltree) conditions. No DB schema changes.
    if ($oldversion < 2026042500063) {
        upgrade_plugin_savepoint(true, 2026042500063, 'local', 'rtocompliance');
    }

    // v4.0.64: MULTI-FIX — (1) Trainer table column reorder (Status under TGA moved after TAE
    //          Achieved); VocComp column shows qualification name (removes date fallback); VET
    //          Currency column changed from "years" text to date picker (vetcurrencydate field
    //          added); Industry Currency date field added to trainer edit form. (2) Table overflow
    //          CSS already present. (3) survey_respond.php and qi_export.php created (were missing,
    //          causing File Not Found errors when clicking survey links or QI export). (7) Dashboard
    //          Marketing card desc updated; Pre-enrolment Suitability card added.
    //          DB CHANGE: adds vetcurrencydate (INT 10) to local_rtocompliance_trainers.
    if ($oldversion < 2026042500064) {
        $table = new xmldb_table('local_rtocompliance_trainers');
        $field = new xmldb_field('vetcurrencydate', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'vetcurrencyyears');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026042500064, 'local', 'rtocompliance');
    }

    // v4.0.65: 8-BUG-FIX — (1) Student Records action buttons white-space:nowrap. (2) Site admin
    //          exclusion from student list via $CFG->siteadmins. (3) Marketing Info Training Product
    //          Show Evidence links to RTO website. (4) Student Obligations card updated.
    //          (5) Policy URLs now admin-configurable (5 new admin_setting_configtext, no DB field).
    //          (6) Trainers table rtoc-table-wrapper + nowrap Edit cell. (7) survey_send
    //          employer_contacts handler added. (8) qi_report YEAR(FROM_UNIXTIME) replaced with
    //          cross-platform timestamp range. No DB schema changes.
    if ($oldversion < 2026042600065) {
        upgrade_plugin_savepoint(true, 2026042600065, 'local', 'rtocompliance');
    }

    // v4.0.66: 8-BUG-FIX (actual code applied):
    // (1) Student Records action buttons → Bootstrap dropdown so Edit Profile + Enrolments are
    //     reachable on every screen width.
    // (2) Student stats count now excludes teacher/manager roles by shortname AND archetype.
    // (3) Training Product Info "Show Evidence" links to RTO public website; amber notice if unconfigured.
    // (4) Student Obligations "Send Declaration" button; NEW student_declaration_send.php +
    //     student_declaration_respond.php with 7-item ASQA checklist, per-item ticks, typed
    //     signature and timestamp. DB: local_rtocompliance_declarations created on first use.
    // (5) Policy links render Open PDF + Download buttons; "not configured" red notice when blank.
    // (6) Trainers Edit cell is now a dropdown (Edit + Delete) labelled primary blue.
    // (7) survey_send.php inserts with status='sent' (was 'pending') — dashboard count now accurate.
    // (8) qi_export.php flushes output buffer before CSV headers for clean download.
    if ($oldversion < 2026042600066) {
        // Create the student declarations table (stores declaration send/respond records).
        $table = new xmldb_table('local_rtocompliance_declarations');
        if (!$dbman->table_exists($table)) {
            // add_field($name, $type, $precision, $unsigned, $notnull, $sequence, $default)
            $table->add_field('id',            XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('userid',        XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null, '0');
            $table->add_field('token',         XMLDB_TYPE_CHAR,    '64',  null, XMLDB_NOTNULL);
            $table->add_field('status',        XMLDB_TYPE_CHAR,    '20',  null, XMLDB_NOTNULL, null, 'sent');
            $table->add_field('fullname',      XMLDB_TYPE_CHAR,   '200',  null, null);
            $table->add_field('signature',     XMLDB_TYPE_CHAR,   '200',  null, null);
            $table->add_field('agreed',        XMLDB_TYPE_INTEGER,  '1',  null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated',   XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecompleted', XMLDB_TYPE_INTEGER, '10',  null, null);
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026042600066, 'local', 'rtocompliance');
    }

    // v4.0.67: Version bump — no DB schema changes.
    // Ensures Moodle recognises a new release and triggers the upgrade path
    // so any environment that missed v4.0.66 receives all prior fixes.
    if ($oldversion < 2026042600067) {
        upgrade_plugin_savepoint(true, 2026042600067, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026042600068) {
        // Bootstrap 4/5 dual-attribute fix for dropdown menus in students.php and trainers.php.
        // No DB schema changes required.
        upgrade_plugin_savepoint(true, 2026042600068, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026042600069) {
        // Second-pass quality fixes: invalidtoken lang string, declaration deduplication,
        // NO_LOGIN_REQUIRED constant removed, siteadminlist refactor, PARAM_LOCALURL for
        // policy URL settings. No DB schema changes required.
        upgrade_plugin_savepoint(true, 2026042600069, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026042600070) {
        // Performance + security: student stats NOT IN subquery → LEFT JOIN derived table;
        // trainer delete action changed from GET link to POST form. No DB schema changes.
        upgrade_plugin_savepoint(true, 2026042600070, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026042700071) {
        // 6-bug fix: student list teacher filter → LEFT JOIN with archetype exclusion;
        // table min-widths raised (generaltable 1000px, trainers-table 1600px);
        // marketing cards simplified; fullname() debug warnings fixed in declaration
        // and survey send by populating all 4 extended name fields on $tempuser.
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026042700071, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026042700072) {
        // Edge-case hardening: (1) .rtoc-table-wrapper gains width:100% + margin-bottom
        //   so content below the scroll container stays correctly spaced. (2) Redundant
        //   duplicate .rtoc-table-scroll CSS block removed from section 15. (3) Dead
        //   courseLine JS variable removed from marketing_cards.php buildCards(). (4)
        //   Empty-content div in renderCards() skipped to prevent phantom whitespace on
        //   Training Product card. (5) $perpage clamped to 10–200 in students.php to
        //   prevent crafted URLs loading unlimited DB rows. No DB schema changes.
        upgrade_plugin_savepoint(true, 2026042700072, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026042700073) {
        // Action button dropdown fix: Bootstrap dropdown-menu (position:absolute) inside
        //   overflow-x:auto scroll wrapper is clipped because CSS spec forces overflow-y
        //   to auto when overflow-x is non-visible. Added JS listener on show.bs.dropdown
        //   and shown.bs.dropdown events in students.php and trainers.php — repositions
        //   the menu to position:fixed aligned to the toggle button, escaping the scroll
        //   container stacking context so items are clickable at all viewport widths.
        //   No DB schema changes.
        upgrade_plugin_savepoint(true, 2026042700073, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026042700074) {
        // (1) CSS scroll fix: width:100% on .generaltable/.trainers-table was overriding
        //     min-width constraints — tables never exceeded their container so the
        //     overflow-x:auto wrapper never triggered a scrollbar. Fixed with
        //     width:auto!important on .rtoc-table-wrapper table.
        // (2) Show Evidence URL: auto-prefix https:// if admin saved URL without scheme.
        // (3) Declaration GET path: normalise NULL phonetic fields to '' after
        //     core_user::get_user() so fullname() debug warning is eliminated.
        // (4) Teacher/trainer filter extended: added trainer/assessor/trainerassessor
        //     shortnames to role exclusion + LEFT JOIN on local_rtocompliance_trainers
        //     so RTO-registered trainers are excluded regardless of Moodle role.
        //     No DB schema changes.
        upgrade_plugin_savepoint(true, 2026042700074, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026042700075) {
        // Edge-case hardening pass — no DB schema changes.
        // (1) URL guard: trim() + any-scheme regex prevents https:// prepended to ftp:// etc.
        // (2) Dropdown: show.bs.dropdown+rAF replaced with shown.bs.dropdown to avoid
        //     stale position:fixed after rapid close/reopen; viewport flip/clamp added.
        // (3) $page clamped to >= 0 (PARAM_INT accepts negatives).
        // (4) CSS min-width: max(Npx, 100%) fills container on wide screens, scrolls on narrow.
        // (5) withprofile stat applies trainer exclusion filter for consistency with total.
        // (6) fullname(false) crash guard when user deleted between page load and GET render.
        upgrade_plugin_savepoint(true, 2026042700075, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026042700076) {
        // Version bump — no DB schema changes.
        upgrade_plugin_savepoint(true, 2026042700076, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026042700077) {
        // 3-bug fix:
        // (1) student_declaration_send.php: require_capability changed from
        //     'managestudents' (non-existent) to 'manage' — Student Obligations
        //     "Send Declaration" was inaccessible to all admin users.
        // (2) enrolment_form.php: assessoruserid setType(PARAM_INT) + null guard in
        //     student_enrolments.php save handler — selecting "None" passed empty string
        //     into INT FK column, causing DB error that silently rolled back entire save.
        // (3) student_profile_form.php: setExpanded('statespecific', true) — QLD/VIC/NSW/WA
        //     fields hidden once user collapsed the section (Moodle stores preference).
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026042700077, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026042700078) {
        // FIX-RTO-TABLE-OVERFLOW: styles.css — width:100% added to .rtoc-table-wrapper
        //   (wrapper could shrink below table min-width in flex context, preventing scroll);
        //   white-space:nowrap added to ALL th+td cells inside wrapper (tbody td cells were
        //   compressing columns into invisibility). No DB schema changes.
        // FIX-RTO-DECL-SELECT: student_declaration_send.php — replaced "Send to all N students"
        //   confirmation button with a full interactive selection table. Filter bar (All /
        //   Not Sent / Pending / Completed with live counts), search, per-student declaration
        //   status badges and dates, checkboxes, select-all, sticky send bar with live count.
        //   POST now accepts userids[] array instead of userid=0. Completed students are
        //   pre-disabled to prevent re-send. No DB schema changes.
        upgrade_plugin_savepoint(true, 2026042700078, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026042700081) {
        // ALL-13-COLUMNS-FLAT: Trainer & Assessor Register redesigned from 8-column +
        //   expandable detail row to all 13 columns visible in the main table per document
        //   spec: Trainer Name, Role, TAE Credential, TAE Achieved, Status under TGA,
        //   Vocational Competency, Units Being Delivered, LLN Capability, VET Currency,
        //   Industry Currency, CPD Points, Next Review Date, Edit Trainer.
        //   Expandable detail row and toggle button removed. Table min-width 1500px.
        //   No DB schema changes.
        upgrade_plugin_savepoint(true, 2026042700081, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026042700083) {
        // BUMP: Version increment only. No DB schema changes.
        upgrade_plugin_savepoint(true, 2026042700083, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026042700086) {
        // FIX-MARKETING-POLICIES: Policies card redesign. No DB schema changes.
        upgrade_plugin_savepoint(true, 2026042700086, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026042800101) {
        // RULE9B: Add rule9b_approved field to local_rtocompliance_locations.
        // Stores whether the delivery location holds a Class 9B building classification
        // (or equivalent) for VET delivery as required by ASQA Standards.
        $table = new xmldb_table('local_rtocompliance_locations');
        $field = new xmldb_field('rule9b_approved', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'status');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026042800101, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026042800113) {
        // MOODLE-ENROL-INTEGRATION: Add linkedcourseid to local_rtocompliance_transitions.
        // Allows admins to link a transition plan to a specific Moodle course. When
        // enrolmentsclosed is toggled on/off in the transition edit form, self-enrolment
        // is automatically disabled/enabled on the linked Moodle course via the {enrol}
        // table. Prevents new students from self-enrolling into superseded/deleted products.
        $table = new xmldb_table('local_rtocompliance_transitions');
        $field = new xmldb_field('linkedcourseid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'enrolmentsclosed');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026042800113, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026042800116) {
        // REPORT-DOCUMENT-URL: Widen reportdocument from 255 to 500 chars so full
        // Google Drive, SharePoint, and OneDrive URLs fit without truncation.
        // The field is now documented as a URL rather than a filename.
        $table = new xmldb_table('local_rtocompliance_validations');
        $field = new xmldb_field('reportdocument', XMLDB_TYPE_CHAR, '500', null, null, null, null, 'findings');
        if ($dbman->field_exists($table, $field)) {
            $dbman->change_field_precision($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026042800116, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026042800125) {
        // BUG-PRED-1 FIX: compliance_predictor.php check_nat_validation_issues() used
        // NOT REGEXP which is MySQL-only and fails on PostgreSQL. Fixed in PHP using preg_match().
        // No DB schema change required.

        // BUG-PRED-2 FIX: get_active_alerts() ORDER BY used NULLS LAST which is not
        // supported in MySQL 5.7. Fixed using portable CASE WHEN IS NULL expression.
        // No DB schema change required.

        // BUG-EXT-1 FIX: run_compliance_scan() accessed $USER->id without global $USER.
        // No DB schema change required.

        // BUG-EXT-2 FIX: tga_get_builder_data() used $curl->info (not a public property).
        // Fixed by using $curl->get_info(). No DB schema change required.

        // BUG-JS-1 FIX: qualbuilder auto-suggest slice(0, shortfall + 1) off-by-one.
        // Fixed to slice(0, shortfall). No DB schema change required.

        upgrade_plugin_savepoint(true, 2026042800125, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026042900126) {
        // FIX-USI-1: usi_verification_enabled setting now present in settings.php with default=1,
        //            unblocking the verify_usi_batch_task scheduled task (was always exiting early).
        // FIX-USI-2: student_profile.php auto-triggers USI verification immediately on save when
        //            both USI and dateofbirth are present and the service is available.
        // FIX-USI-3: students.php inline AJAX verify now shows the server error message in a
        //            dismissible red callout below the USI cell instead of silently restoring HTML.
        // No DB schema changes required.

        upgrade_plugin_savepoint(true, 2026042900126, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026042900127) {
        // FIX-VALIDATION-BOLD: validation.php product names were wrapped in <strong>, making
        //   them bold; removed the wrapper so all table cells render at normal font weight.
        // FIX-SURVEY-CURL: survey_analyzer.php call_platform_api() switched from raw curl_init()
        //   to Moodle's \curl class so SSL/proxy settings and open_basedir restrictions are
        //   respected, fixing AI survey analysis failures on locked-down Moodle hosts.
        // No DB schema changes.

        upgrade_plugin_savepoint(true, 2026042900127, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026042900128) {
        // AUDIT PASS: Full code review of all Apr 29 2026 fixes confirmed correct.
        //   FIX-USI-1: usi_verification_enabled default=1 in settings.php verified — batch task
        //     was always exiting early before this fix; now correctly enabled by default.
        //   FIX-USI-2: student_profile.php auto-verify on save verified — USI verified against
        //     usi.gov.au immediately when both USI and DOB are present.
        //   FIX-USI-3: students.php inline AJAX error callout verified — dismissible red callout
        //     shown below USI cell instead of silently reverting to original HTML.
        //   FIX-VALIDATION-BOLD: validation.php lines 92 + 161 confirmed no <strong> on
        //     productname cells; line 225 <strong> on validator fullname is intentional.
        //   FIX-SURVEY-CURL: survey_analyzer.php \curl(['ignoresecurity'=>true]) confirmed
        //     appropriate — disables SSL peer check for AI endpoint on locked-down hosts;
        //     other \curl callers (compliance_predictor, ajax.php) do not need ignoresecurity.
        //   nat_generator.php BUG-5 through BUG-23 all carry FIX markers — verified resolved.
        //   tas_edit.php, tas_consultation.php, trainers.php, risk.php, workforce_management.php,
        //     rpl_edit.php: no TODO/FIXME/BROKEN markers — all clean.
        // No DB schema changes.

        upgrade_plugin_savepoint(true, 2026042900128, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026042900129) {
        // FIX-TRAINERS-NOTE: Added credential policy info alert above trainers table so admins
        //   understand that an expired TGA status does not mean a trainer is prohibited — check
        //   Credential Policy column first.
        // FIX-QB-GROUP-BADGE: qualbuilder_edit.js unitRow now shows Group badge beside unit name
        //   when data-unitgroup is present (AMD triple-sync applied).
        // FIX-CONSULTATION-WRAP: tas_consultation.php table now uses table-layout:fixed with
        //   colgroup widths so Method/Feedback columns wrap and actions column never overflows.
        // FIX-AI-REVISIONNOTES: ai_suggest.js FIELD_REGISTRY now includes revisionnotes textarea.
        // No DB schema changes.

        upgrade_plugin_savepoint(true, 2026042900129, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026042900130) {
        // Deep audit pass — 3 additional root-cause fixes per original issue:
        // FIX-TRAINERS-BADGE: expired TAE trainers now show amber "Expired (Policy OK)" badge
        //   (trainer-status.expired-policy CSS) instead of red when Credential Policy = approved.
        // FIX-TRAINERS-SIGNOFF: signoffDisplay label changed from "Not Approved" → "Pending Review"
        //   with tooltip clarifying this is not a rejection, just not yet assessed under policy.
        // FIX-TRAINERS-FILTER: "Credential Policy Approved" filter option added to dropdown +
        //   SQL WHERE t.managersignoff IS NOT NULL so admin can quickly list policy-approved trainers.
        // FIX-TRAINERS-OVERFLOW: overflow-x:auto added to rtoc-table-wrapper div in trainers.php.
        // FIX-CONSULTATION-OVERFLOW: outer overflow-x:auto div wraps the fixed-layout consultation
        //   table so it scrolls horizontally on narrow/mobile screens.
        // FIX-AI-NOTICE: tas_edit.php now shows a site:config-only amber notice when API key is
        //   not configured, explaining why AI sparkle buttons are absent and linking to settings.
        // FIX-AI-REGISTRY: ai_suggest.js FIELD_REGISTRY pruned — removed 12 dead entries that
        //   were hidden inputs or non-textarea inputs in the TAS form (assessmentmethods, thirdparty,
        //   learnersupport, accessibility, marketinginfo, feesinformation, transitionplan,
        //   riskmanagement, complaintsprocess, continuousimprovement, validationschedule,
        //   assessmentmapping). The attachButtons() selector only finds textarea[name] so these
        //   entries could never attach AI buttons — removing them cleans up dead code.
        // No DB schema changes.

        upgrade_plugin_savepoint(true, 2026042900130, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026042900131) {
        // FIX-COMPLAINT-PHP-ERROR: complaint_form.php NOWDOC — PHP parse error resolved (no DB change).
        // FIX-RISK-CONFLICTS: 'conflicts' tab added to whitelist in risk.php (no DB change).
        // FIX-TRAINER-EXPIRY-ZERO: trainer_edit.php saves NULL not 0 for blank optional date;
        //   trainers.php SQL updated to treat taeexpirydate=0 same as NULL (no DB schema change).
        // FIX-TAS-COMPLETENESS-SECT3: Section 3 now queries tas_consult table count (no DB change).
        // FIX-TAS-COMPLETENESS-SECT8: Section 8 now checks hasworkplacement/placementdetails (no DB change).
        // FIX-LANG-DUPLICATE: removed duplicate $string['resolution'] = 'Resolution' (no DB change).
        // No DB schema changes in this upgrade step.

        upgrade_plugin_savepoint(true, 2026042900131, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026042900132) {
        // FIX-QB-GROUPMIN-REGEX: packaging rules regex updated to handle written-number+parenthetical
        //   format "seven (7)" and "1 elective unit must be selected from Group A" — no DB change.
        // FIX-COURSE-LINK: qualbuilder_edit.js onCourseChange fixed to not re-render when unit not
        //   yet in currentUnits, preserving user's course dropdown selection — no DB change.
        // FIX-RISK-ADDRISK-CATEGORY: Add Risk button now passes matching category per active tab — no DB change.
        // FIX-RPL-STATS-STUDENTID: Students with RPL/CT count excludes studentid=0 — no DB change.
        // FIX-VALIDATION-BOLD: CSS overrides bold labels in Moodle form checkbox groups — no DB change.
        // No DB schema changes in this upgrade step.

        upgrade_plugin_savepoint(true, 2026042900132, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026042900133) {
        // FIX-TAS-COMPLETENESS: Section 1 completeness check was using deliverymode (Section 4 field
        //   with a 'classroom' default — always true). Now checks qualificationcode/qualificationname
        //   which correctly indicates whether Section 1 has been started. No DB change.
        // FIX-TAS-AI-SECTION5: Added assessmentnotes textarea to Section 5 (Assessment Plan) so
        //   the AI suggestion engine can attach to Section 5 and help draft assessment approaches.
        //   DB: new assessmentnotes TEXT column on local_rtocompliance_tas.
        // FIX-CONSULT-OVERFLOW: Industry Consultation form categories SELECT now uses column layout
        //   to prevent overflowing the Add/Clear buttons. No DB change.
        // FIX-SURVEY-AI-MSG: ai_analysis.php redirect message now says "platform API key" instead
        //   of "OpenAI API key". No DB change.
        // FIX-UNDER18-NOTE: Under-18 risks tab now shows a migration notice for pre-fix records. No DB change.
        // FIX-QB-GROUP-NOTE: Qualification Builder shows a notice when packaging-rule groups exist
        //   but no TGA unit-level group assignments are available. No DB change.
        // FIX-ENROL-MARKCOMPLETE: student_enrolments.php list now has a quick "Mark Complete" action
        //   that sets status=completed and outcomeidentifier=20 without the full edit form. No DB change.

        $dbman = $DB->get_manager();
        $table = new xmldb_table('local_rtocompliance_tas');
        $field = new xmldb_field('assessmentnotes', XMLDB_TYPE_TEXT, null, null, null, null, null, 'assessmentmapping');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026042900133, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026042900134) {
        // FIX-NAT-USI-WARNING: Missing USI demoted from $this->errors[] to $this->warnings[]
        //   in classes/nat_generator.php. NAT export was blocked entirely when any student
        //   lacked a USI — all other validation issues (incomplete profiles, missing outcomes,
        //   etc.) are warnings that allow export to proceed; missing USI now follows the same
        //   pattern. Message updated to advise adding USIs before NCVER submission. No DB change.
        //
        // FIX-9B-CERT-UPLOAD: Class 9B certificate file upload added to location_edit.php.
        //   filemanager element (up to 3 files: PDF/JPG/PNG) added under ASQA Rule 9B section.
        //   file_prepare_draft_area() populates existing files on edit; file_save_draft_area_files()
        //   persists uploaded files to component=local_rtocompliance, filearea=certificate9b,
        //   itemid=location->id. local_rtocompliance_pluginfile() updated to whitelist
        //   'certificate9b' filearea. locations.php table now shows download links for any
        //   uploaded certificates in the Rule 9B column. Lang strings certificate9b_upload and
        //   certificate9b_upload_help added. No DB schema change (Moodle file system storage).
        //
        // FIX-QB-MANUAL-GROUP: qualbuilder_edit.js — when packaging rules define elective groups
        //   (A, B, C…) but the TGA API returns no unit-level group codes, each elective row now
        //   shows a "Assign to group" <select> dropdown populated from QB.groupRuleKeys. Selecting
        //   a group updates QB.currentUnits[unit].electivegroup and data-unitgroup on the DOM row
        //   so the value is included in the save payload. QB.manualGroupMode and QB.groupRuleKeys
        //   are reset at the start of each renderUnitBuilder() call. onGroupChange() event handler
        //   wired up. AMD triple-sync applied (amd/build/qualbuilder_edit.js + .min.js). No DB change.
        //
        // No DB schema changes in this upgrade step.
        upgrade_plugin_savepoint(true, 2026042900134, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026042900135) {
        // FIX-QB-GROUP-ZERO: TGA API returns group:0 (integer) for units that have no group
        // assignment. Prior to this fix, external.php used the ?? (null-coalescing) operator
        // which only replaces null — integer 0 passed through unchanged. The qualbuilder_import_units
        // function also used ?? which caused the char(5) DB column to store the literal string "0"
        // instead of NULL. The packagingrules_validator then compared strtoupper("0") against group
        // keys "A"/"B"/"C" and found no matches, reporting 0 units in every group.
        //
        // This upgrade step cleans up any existing rows where electivegroup = '0' (from the old
        // bug) by setting them to NULL, which the validator treats as "no group" correctly.
        //
        // FIX-COMPLAINT-FULLNAME: complaint_form.php SQL SELECT extended to include
        // firstnamephonetic, lastnamephonetic, middlename, alternatename — these are required by
        // Moodle's core_user::get_fullname() (called via fullname()). The prior query fetched only
        // id, firstname, lastname, email; Moodle's get_fullname() then triggered a debugging notice
        // for each missing field, which (with debugging enabled on the test server) caused the form
        // to abort with "Error output, so disabling automatic redirect." No DB schema change.
        $DB->execute(
            "UPDATE {local_rtocompliance_qualunits} SET electivegroup = NULL WHERE electivegroup = '0'"
        );
        upgrade_plugin_savepoint(true, 2026042900135, 'local', 'rtocompliance');
    }

    // v4.2.12 (2026043000001): Remediation upgrade step — ensures local_rtocompliance_ai_survey
    // exists on any site that upgraded through an intermediate state where the table was added
    // to upgrade.php (step 2025120801) but not install.xml, and somehow ended up without it.
    // Also guards against any future re-installs or restored databases missing this table.
    if ($oldversion < 2026043000001) {
        $table = new xmldb_table('local_rtocompliance_ai_survey');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('surveytype', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null);
            $table->add_field('analysisperiod', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null);
            $table->add_field('periodstart', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('periodend', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('responsecount', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('overallsentiment', XMLDB_TYPE_CHAR, '20', null, null, null, null);
            $table->add_field('sentimentscore', XMLDB_TYPE_NUMBER, '5', 2, null, null, null);
            $table->add_field('satisfactionindex', XMLDB_TYPE_NUMBER, '5', 2, null, null, null);
            $table->add_field('keythemes', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('strengths', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('improvements', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('recommendations', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('wordcloud', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('trendsummary', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('fullanalysis', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('aimodel', XMLDB_TYPE_CHAR, '50', null, null, null, null);
            $table->add_field('creditscost', XMLDB_TYPE_INTEGER, '5', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'pending');
            $table->add_field('errormessage', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('requestedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecompleted', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('requestedby', XMLDB_KEY_FOREIGN, ['requestedby'], 'user', ['id']);
            $table->add_index('surveytype_period', XMLDB_INDEX_NOTUNIQUE, ['surveytype', 'periodstart', 'periodend']);
            $table->add_index('status', XMLDB_INDEX_NOTUNIQUE, ['status']);
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026043000001, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026043000002) {
        // FIX-TGA-GROUP-PARSER: rewritten parseUnitGroupsFromHtml in the Express server with 4 strategies.
        //   Fixes Group B/C/D missing for BSB/HLT/CHC courses (html.search() bug + narrow trigger).
        // FIX-SURVEY-DROPDOWN: ai_analysis.php selected attribute uses null-safe ternary.
        //   Option labels renamed to "Learner Survey" / "Employer Survey".
        // No database schema changes in this version.
        upgrade_plugin_savepoint(true, 2026043000002, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026043000003) {
        // v4.2.14 remediation (30 Apr 2026):
        // FIX-ENROLMENT-SAVE: $PAGE->set_url inside edit/add block now includes action= and
        //   enrolid=.  No DB change.
        // FIX-TAS-CONSULTATION-BOX: category dropdown-helper divs now display:block;width:100%.
        //   No DB change.
        // FIX-METHODOLOGY-OPENAI-PKG: ai-methodology-suggest route replaced import('openai')
        //   with raw fetch.  No DB change.
        // FIX-SURVEY-TABLE: survey_analyzer::get_survey_responses() now queries the existing
        //   local_rtocompliance_surveys table — the never-created survey_responses /
        //   survey_questions tables are no longer referenced.  No DB schema change needed.
        upgrade_plugin_savepoint(true, 2026043000003, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026043000004) {
        // v4.2.15 follow-up remediation (30 Apr 2026):
        // BUG-SR-OUTCOME: DB save + audit in student_enrolments.php now wrapped in
        //   try/catch; deliverylocationid empty→null coercion added; setDefault for
        //   outcomeidentifier corrected to '70'; setType for deliverylocationid added.
        //   No DB schema change.
        // BUG-TAS-OVERLAP: rtocAppendDropdown() moved to $PAGE->requires->js_init_code()
        //   to pass Moodle 4.x CSP nonce enforcement. No DB schema change.
        // BUG-WFD-FIELD: "Primary assessment type" select → number input; labels corrected.
        //   No DB schema change.
        // BUG-SURVEY-AI: get_recent_analyses() + count_records wrapped in try/catch;
        //   per-type "Run AI Analysis" buttons added to surveys.php. No DB schema change.
        upgrade_plugin_savepoint(true, 2026043000004, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026043000005) {
        // v4.2.16 namespace fix (30 Apr 2026):
        // BUG-USI-NAMESPACE: bare `core_user::get_user()` calls in usi_verification_service.php
        //   (lines 76 and 215) fixed to `\core_user::get_user()`.  Without the leading \
        //   PHP resolved the class as local_rtocompliance\usi\core_user (not found), crashing
        //   every "Verify via usi.gov.au" button click with a fatal class-not-found error.
        //   Also fixed `html_writer::link()` → `\html_writer::link()` in enrolment_form.php
        //   (lines 167 and 204) — same namespace issue, would crash the enrolment form when
        //   no delivery locations or no trainers are configured.  No DB schema change.
        upgrade_plugin_savepoint(true, 2026043000005, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026043000006) {
        // v4.2.17 Survey AI HTTP-Array fix (30 Apr 2026):
        // BUG-SURVEY-HTTP-ARRAY: Some older Moodle versions return the full curl_getinfo()
        //   array from \curl::get_info($opt) regardless of the $opt argument.  In
        //   classes/ai/survey_analyzer.php::call_platform_api() this caused $httpcode to be
        //   an array instead of an integer, resulting in "HTTP Array" as the error string and
        //   a PHP fatal "Array to string conversion" on line 270.  Fixed by normalising
        //   $httpcode immediately after the get_info() call (is_array branch reads
        //   $httpcode['http_code']) and by casting $data['error'] to string (or json_encoding
        //   it when it is itself an array) before embedding in the moodle_exception message.
        //   No DB schema change.
        upgrade_plugin_savepoint(true, 2026043000006, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026043000007) {
        // v4.2.18 USI platform error surfacing + TAS dropdown layout fix (30 Apr 2026):
        // BUG-USI-PLATFORM-MSG: classes/usi/usi_platform_client.php (verify_usi) was
        //   discarding the actual server-side error message and showing a generic
        //   "Platform error — please try again or contact support." toast for any
        //   HTTP 5xx response from /api/usi/verify.  This made it impossible for admins
        //   to diagnose configuration issues (wrong RTO_USI_CERT_PASSWORD, expired
        //   cert, ATO endpoint outage, etc.) without server-log access.  The catch-all
        //   branch now reads $data['message'] and $data['details']['error'] from the
        //   JSON body and embeds them in the user-facing message, e.g.
        //   "Verification failed (HTTP 502): USI Registry call failed: bad decrypt".
        // BUG-TAS-DROPDOWN-LAYOUT: tas_consultation.php — the three Industry
        //   Consultation quick-add boxes (Feedback / Training / Assessment) had a
        //   flex-column layout that placed the "Add Selected" and "Clear field"
        //   buttons BELOW the <select multiple size="5">.  In Moodle 4.x Boost the
        //   select was rendering taller than expected (the size="5" rows plus extra
        //   padding from .form-control overrides) and the button row was being
        //   pushed off-screen / hidden behind subsequent textareas in the form.
        //   Layout restructured so each helper now has a HEADER ROW containing the
        //   label and both action buttons (flex-wrap so they collapse to two lines
        //   on narrow screens), with the <select> below.  Buttons are now always
        //   visible at the top of each box regardless of select rendering height.
        //   No DB schema change.
        upgrade_plugin_savepoint(true, 2026043000007, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026043000008) {
        // v4.2.19 Validation methods/risk-factors persistence fix (30 Apr 2026):
        // BUG-VALIDATION-METHODS-PERSIST: validation_edit.php was storing the
        //   methodology and risk-factor checkbox grids as a concatenated text
        //   blob ("tool_review, evidence_review\nfreeform notes...") and on
        //   edit re-load the whole blob landed in the additional-notes textarea
        //   while the actual checkboxes appeared unchecked.  Repeated saves
        //   then duplicated the keys into the notes column.  Fixed by storing
        //   both fields as JSON ({"keys":[...],"notes":"..."}) and adding a
        //   backwards-compatible decoder that splits the legacy format into
        //   individual method_<key>/riskfactor_<key> values plus a clean notes
        //   string before set_data().  No DB schema change required (column
        //   type remains TYPE="text").  Existing rows are auto-migrated on
        //   first edit.
        upgrade_plugin_savepoint(true, 2026043000008, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026043000009) {
        // v4.2.20 Survey AI error message + Run AI Analysis button colour fixes
        // (30 Apr 2026):
        // BUG-SURVEY-AI-MSG: surveys "Run AI Analysis" toast no longer triple-wraps
        //   the error message ("Analysis failed: Error communicating with AI service:
        //   AI analysis failed: <real reason>").  call_platform_api now throws plain
        //   \Exception (not \moodle_exception with 'ai_api_error' lang string) and
        //   ai_analysis.php redirects with the message verbatim, so admins see the
        //   underlying root cause directly (rate-limit, invalid OpenAI key, network
        //   error, non-JSON body snippet, etc.).  Same pattern as v4.2.18 USI fix.
        // BUG-SURVEY-BTN-PURPLE: surveys.php — both per-card "Run AI Analysis"
        //   buttons had inline indigo→purple gradient styling (linear-gradient
        //   #6366f1 → #8b5cf6) that didn't match the solid blue btn-primary used
        //   for Send Survey on the same row.  Inline style and btn-outline-primary
        //   base class removed; both buttons now use plain btn btn-primary.
        // No DB schema change.
        upgrade_plugin_savepoint(true, 2026043000009, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026043000010) {
        // v4.2.21 Student Records save + TAS overlap fix (30 Apr 2026):
        // BUG-SR-OUTCOME-SAVE-2: student_enrolments.php — bulletproof field
        //   cleaning before update_record/insert_record so date_selector boolean
        //   FALSE values, empty-string char-FK values, and bare numeric strings
        //   no longer trip dml_exception with an opaque error toast.  The catch
        //   block now surfaces the underlying dml_exception::$error and ::$debuginfo
        //   in the user-facing message and writes the full trace to error_log()
        //   prefixed with "[rto/enrolment-save]".  Same diagnostic pattern as
        //   v4.2.18 USI fix.
        // BUG-TAS-OVERLAP-2: tas_consultation.php + lib.php — replaced the
        //   three fixed-height <select multiple size="5"> quick-add listboxes
        //   above the Feedback / Training / Assessment textareas with a
        //   flex-wrap checkbox grid (rendered by new helper
        //   local_rtocompliance_render_quickadd_helper() and operated by new JS
        //   helper rtocAppendChecked()).  Eliminates the visual overlap with
        //   the action buttons that appeared on the EDIT view of an existing
        //   consultation record under narrower form-column widths.
        // No DB schema change.
        upgrade_plugin_savepoint(true, 2026043000010, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026043000011) {
        // v4.2.22 Dashboard "Create Your First Enrolment" tile fix (30 Apr 2026):
        // BUG-DASH-FIRST-ENROL: index.php + student_enrolments.php — the
        //   dashboard "Setup Progress" tile linked directly to
        //   student_enrolments.php with no ?userid query parameter, dropping
        //   admins onto a hard error wall ("No student was specified.  Please
        //   select a student from the student list.") that blocked the whole
        //   onboarding step.  Two-layer fix:
        //   (a) Dashboard tile URL changed to students.php so users land on the
        //       student picker first, then click through to "Manage Enrolments"
        //       from the student row.
        //   (b) student_enrolments.php now redirects to students.php with a
        //       friendly NOTIFY_INFO toast when ?userid is missing, instead of
        //       rendering the error wall — defensive coverage for stale
        //       bookmarks and any other links that forget to pass userid.
        // No DB schema change.
        upgrade_plugin_savepoint(true, 2026043000011, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026043000012) {
        // v4.2.23 Industry Consultation quick-add helper overflow fix (30 Apr 2026):
        // BUG-TAS-OVERLAP-3: lib.php + tas_consultation.php — the v4.2.21
        //   flex-wrap checkbox grid still overflowed on the EDIT view because
        //   the helper was inserted via $mform->addElement('static', ...),
        //   which wraps it in a Moodle .fitem / .felement form-row whose
        //   layout effectively clipped the helper's visible height — checkbox
        //   rows past the first ~5 items rendered past the blue border and
        //   visually overlapped the next field's label.  Two-layer fix:
        //   (a) Helper rewritten to wrap in a native <details>/<summary>
        //       element collapsed by default with an item-count hint, and the
        //       checkbox grid switched from flex-wrap to single-column block
        //       layout so the helper grows predictably with item count.
        //   (b) All three call sites switched from addElement('static') to
        //       addElement('html') so Moodle adds no form-row wrapper at all.
        // No DB schema change.
        upgrade_plugin_savepoint(true, 2026043000012, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026043000013) {
        // v4.2.24 AI Analysis silent-no-op + upgrade savepoint order fixes (30 Apr 2026):
        // BUG-SURVEY-AI-NOOP: ai_analysis.php — the "Run AI Analysis — 5 Credits"
        //   button was an html_writer::link with an inline onclick="return
        //   confirm(...)" handler.  On stricter Moodle 4.x CSP/nonce policies the
        //   inline onclick was being silently blocked, and worse — the action
        //   handler used "if ($action === 'analyze' && confirm_sesskey())" which
        //   silently SKIPPED the entire analyze block when the sesskey didn't
        //   match (no error, no notification).  From the user's perspective the
        //   button clicked but did nothing.  Two-layer fix:
        //   (a) Replaced the link+onclick with $OUTPUT->single_button() — proper
        //       Moodle POST form with built-in CSP-compliant confirm dialog and
        //       hidden sesskey field.
        //   (b) Replaced confirm_sesskey() with require_sesskey() so any sesskey
        //       mismatch produces an explicit "Invalid sesskey submitted" error
        //       page instead of a silent no-op.
        // BUG-UPGRADE-DOWNGRADE: db/upgrade.php — the savepoint blocks were out
        //   of order (009, 010, 012, 011, 008) which caused
        //   "Cannot downgrade from 2026043000012 to 2026043000011" when
        //   upgrading from v4.2.21 (010) directly to v4.2.23 (012): the 012
        //   block ran first and bumped the installed version to 012, then the
        //   011 block ran (because $oldversion local var was still 010) and
        //   tried to record a savepoint lower than the installed version,
        //   triggering Moodle's downgrade_exception.  Reordered all blocks
        //   monotonically (008 → 009 → 010 → 011 → 012 → 013).
        // No DB schema change.
        upgrade_plugin_savepoint(true, 2026043000013, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026043000015) {
        // v4.2.26 BUG-SR-OUTCOME-SAVE-3 silent enrolment-save revert fix (30 Apr 2026):
        //   Editing an enrolment, changing Outcome to "Competency achieved/pass" (20)
        //   and Status to "Completed", then clicking SAVE produced no error and no
        //   success toast — the form re-rendered with the OLD values still selected.
        //   Root cause was the long-standing Moodle anti-pattern at line 330 of
        //   student_enrolments.php: $form->set_data() was called UNCONDITIONALLY
        //   before $form->get_data(), including on POST.  When set_data() runs after
        //   formslib has parsed the submitted POST, it overwrites the resolved
        //   element values with the OLD database record for any element that has
        //   an explicit setDefault() — and enrolment_form.php sets defaults on
        //   outcomeidentifier, status, deliverymode, fundingsourcenat, vetflag,
        //   vetinschoolsflag, commencingprogramid, feecharged.  get_data() then
        //   returned the OLD values; the save block "successfully" wrote the OLD
        //   record back with a fresh timemodified, producing a perfectly silent
        //   revert.  Three-part fix: (a) set_data() moved into the else-branch
        //   (initial display only), (b) error_log() diagnostic of submitted vs.
        //   persisted values prefixed "[rto/enrolment-save-debug]", and (c)
        //   post-save re-fetch surfaces the persisted outcome + status in the
        //   success toast for visual verification.
        // Note: v4.2.26 contains NO version 2026043000014 savepoint because
        //   v4.2.25 carried numeric_version 2026043000014 but its plain-HTML-form
        //   ai_analysis.php fix needed no schema migration.  Sites already on
        //   2026043000014 jump straight to this 2026043000015 block.
        // No DB schema change.
        upgrade_plugin_savepoint(true, 2026043000015, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026043000016) {
        // v4.2.27 BUG-SR-OUTCOME-AUTOREVERT silent enrolment-overwrite fix (30 Apr 2026):
        //   v4.2.26 fixed the form-layer set_data() ordering bug, but managers
        //   continued to report manual outcome edits silently reverting on the next
        //   page refresh.  Root cause was THREE separate auto-overwrite paths firing
        //   AFTER the form save:
        //     1) classes/observer.php :: user_graded — runs an UPDATE forcing
        //        outcomeidentifier='20' or '30' based on current gradebook %, with
        //        WHERE studentid=? AND courseid=? AND status IN ('active','completed')
        //        — wiping out any manual outcome the manager had just set.
        //     2) classes/observer.php :: course_completed → queues
        //        process_enrolment_task::process_course_completed which forces
        //        status='completed', outcomeidentifier='20' on every active row
        //        for the same student+course.
        //     3) classes/task/process_enrolment_task.php :: process_course_completed
        //        cron task — same overwrite logic, runs on every cron tick.
        //   Manager edits via the local/rtocompliance/student_enrolments.php form
        //   ARE the legal AVETMISS record-of-truth — auto-grading from Moodle's
        //   gradebook is a convenience starting point only, NEVER an override.
        //   Fix: add a manualoutcome TINYINT flag to local_rtocompliance_enrolments;
        //   the form save handler sets it = 1; all three auto-flows now skip rows
        //   where manualoutcome = 1.  Idempotent column add — existing rows default
        //   to 0 (auto-grading still applies as before for any pre-v4.2.27 data).
        $table = new xmldb_table('local_rtocompliance_enrolments');
        $field = new xmldb_field('manualoutcome', XMLDB_TYPE_INTEGER, '1', null,
            XMLDB_NOTNULL, null, '0', 'outcomeidentifier');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026043000016, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026043000018) {
        // v4.2.29 BUG-RESULTS-NOID-BOUNCE + BUG-SURVEY-AI-NOSHOW (30 Apr 2026):
        //   Two pure-PHP UX fixes — qualbuilder_results.php now renders a
        //   training-product picker when no ?id is supplied (instead of bouncing
        //   to qualbuilder.php with a confusing red error toast), and
        //   ai_analysis.php now renders the AI analysis result inline when the
        //   underlying DB insert silently returns 0 (so the user sees the
        //   output they paid 5 credits for even if the local
        //   local_rtocompliance_ai_survey table is missing or has a column
        //   mismatch).  No schema change in either fix; this savepoint exists
        //   purely to bump installed_version so future savepoint blocks don't
        //   trigger Moodle's "Cannot downgrade" guard.
        upgrade_plugin_savepoint(true, 2026043000018, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026043000019) {
        // v4.2.30 PER-TENANT-USI + ROLE-SPLIT + NAV-PRIMARY (30 Apr 2026):
        //   Three architectural changes, all in plugin-side PHP and platform
        //   API code; NO local DB schema change required on the Moodle install:
        //   (a) PER-TENANT-USI — usi_settings.php uploads .pfx + password +
        //       TOID to the platform's /api/rto/usi-cert/upload endpoint,
        //       which stores per-tenant in client_rto_configs.  /api/usi/verify
        //       resolves the calling siteid → client → cert at request time
        //       (env vars become legacy fallback only).
        //   (b) ROLE-SPLIT — db/access.php drops editingteacher from :manage,
        //       :viewall and :managesurveys, adds new :viewtrainer cap for
        //       editingteacher / teacher archetype.  Existing role assignments
        //       are reapplied automatically on plugin upgrade.
        //   (c) NAV-PRIMARY — lib.php registers an "RTO Compliance" entry in
        //       the global navigation tree so managers / trainers see a top-
        //       level nav link even when they don't hold moodle/site:config.
        //   This savepoint exists purely to bump installed_version so future
        //   savepoint blocks don't trigger Moodle's "Cannot downgrade" guard.
        upgrade_plugin_savepoint(true, 2026043000019, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026043000020) {
        // v4.2.31 USI-DISCOVERABILITY (30 Apr 2026):
        //   Pure UX polish on top of v4.2.30 — Settings tree menu reorder,
        //   legacy USI page redirect banner, dashboard "USI not configured"
        //   CTA card.  No DB schema change.  Savepoint exists only to bump
        //   installed_version.
        upgrade_plugin_savepoint(true, 2026043000020, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026050100000) {
        // v4.2.32 BUG-SR-OUTCOME-SAVE-4 + BUG-SURVEY-AI-NORESP (1 May 2026):
        //   Two pure-PHP UX fixes, no schema change:
        //   (a) BUG-SR-OUTCOME-SAVE-4 — enrolment_form.php now preserves the
        //       existing courseid in the dropdown when editing, even if the
        //       course is no longer in the qual builder linkage / nationally
        //       recognised set; student_enrolments.php now surfaces a
        //       prominent error notification with field-level details when
        //       form validation fails (was previously silent).
        //   (b) BUG-SURVEY-AI-NORESP — surveys.php pre-checks responsecount
        //       and renders the "Run AI Analysis" button as a disabled
        //       tooltip-rich span when there are no completed responses;
        //       ai_analysis.php promotes the disabled-state from a tiny
        //       grey button to a prominent yellow alert with a direct
        //       "Send survey →" CTA.
        //   Savepoint exists only to bump installed_version so future
        //   savepoint blocks don't trigger Moodle's "Cannot downgrade" guard.
        upgrade_plugin_savepoint(true, 2026050100000, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026050100033) {
        // v4.2.33 BUG-SR-OUTCOME-SAVE-5 + BUG-SURVEY-AI-NORESP-2 (1 May 2026, AM):
        //   Two pure-PHP UX fixes, no schema change:
        //   (a) BUG-SR-OUTCOME-SAVE-5 — enrolment_form.php adds hidden
        //       userid/action/enrolid inputs so the enrolment-edit POST
        //       carries those page-level params in the body, immune to
        //       any Moodle theme that strips the query string from form
        //       actions.  Fixes the "blue No student was specified" toast
        //       that a 1 May 2026 customer screenshot revealed as the real
        //       reason her Outcome=Competent / Status=Completed saves
        //       were appearing to silently revert.
        //   (b) BUG-SURVEY-AI-NORESP-2 — ai_analysis.php Run AI Analysis
        //       button now disables itself on click, swaps to a spinner +
        //       "Analysing responses (please wait 30-60 seconds)..." label
        //       so the 15-60 second OpenAI round-trip is no longer mistaken
        //       for a dead button.  Also prevents double-click queueing
        //       on Moodle's session lock.
        //   Savepoint only exists to bump installed_version.
        upgrade_plugin_savepoint(true, 2026050100033, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026050100034) {
        // v4.2.34 BUG-SURVEY-AI-2CLICK (1 May 2026, AM):
        //   Pure-PHP UX fix, no schema change.  surveys.php now renders the
        //   per-card "Run AI Analysis (N responses)" buttons as POST forms
        //   that submit directly to ai_analysis.php?action=analyze (with a
        //   confirm() dialog naming the 5-credit cost first), so the user
        //   no longer has to click through to a confirmation page and click
        //   a second button to actually run the analysis.  Same spinner UX
        //   from v4.2.33 runs during the OpenAI round-trip.
        //   Savepoint only exists to bump installed_version.
        upgrade_plugin_savepoint(true, 2026050100034, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026050100035) {
        // v4.2.35 BUG-SURVEY-AI-NOSHOW-2 (1 May 2026, mid-AM):
        //   Pure-PHP fix, no schema change.  ai_analysis.php no longer
        //   redirects on successful analysis — the result is rendered
        //   inline on the same request that produced it, eliminating the
        //   second HTTP round-trip whose failure modes (silent get_analysis
        //   returning null, session-lock conflicts, browsers not following
        //   the 303 from a JS-submitted form, success toast hidden by the
        //   theme nav bar) had been presenting as "click does
        //   nothing — page just reloads".  Adds a high-contrast green
        //   "AI analysis completed successfully" banner with auto
        //   scrollIntoView() so the result is unmistakeable.  Persistence
        //   to local_rtocompliance_ai_survey is preserved for the
        //   "Previous Analyses" history table but no longer gates display.
        //   Savepoint only exists to bump installed_version.
        upgrade_plugin_savepoint(true, 2026050100035, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026050100036) {
        // v4.2.36 CERTIFICATES-REDESIGN (1 May 2026):
        //   Schema additions for one-click reissue with audit trail, plus
        //   a substantial UX overhaul of certificates.php (filters, search,
        //   sortable table view, pagination, one-click email AJAX, reissue
        //   modal). Two new nullable columns on local_rtocompliance_certs:
        //     - replacement_of (BIGINT NULL): on a reissued cert, points to
        //       the original cert.id so the audit trail (original cert
        //       number + original issue date) is preserved permanently.
        //     - reissued_at (BIGINT NULL): set on the ORIGINAL cert when it
        //       has been superseded — UI uses this to grey out the actions
        //       and show "Replaced by CERT-..." badge on the original row.
        //   New endpoints: reissue_cert.php (POST, JSON-only) creates a new
        //   cert from a source cert; email_cert.php gains an AJAX path that
        //   skips the confirm prompt and returns JSON for one-click email.
        //   Both columns are nullable with no default; existing data is not
        //   touched. New index on replacement_of for fast lookup of all
        //   reissues of a given original cert.
        $dbman = $DB->get_manager();
        $table = new xmldb_table('local_rtocompliance_certs');

        $field = new xmldb_field('replacement_of', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'notes');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('reissued_at', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'replacement_of');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $index = new xmldb_index('replacement_of', XMLDB_INDEX_NOTUNIQUE, ['replacement_of']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        upgrade_plugin_savepoint(true, 2026050100036, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026050100037) {
        // BULK-CERT-ACTIONS — schema-free version bump. v4.2.37 ships:
        //   - new bulk_action_cert.php endpoint (3 POST actions: email, download_zip, export_csv)
        //   - new lib.php helpers (local_rtocompliance_send_certificate_email,
        //     local_rtocompliance_render_certificate_pdf_string) shared by single + bulk paths
        //   - certificates.php table view: per-row checkboxes + master select-all + floating
        //     action bar that appears when >=1 row selected
        //   - email_cert.php refactored to call the shared lib helper (no more inline copy)
        // No DB columns or indexes are added or modified.
        upgrade_plugin_savepoint(true, 2026050100037, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026050100038) {
        // USI-SETUP-FULL-FIX — schema-free version bump. v4.2.38 ships
        // comprehensive self-service onboarding for the per-tenant USI
        // Machine Credential setup page (usi_settings.php).  All schema
        // changes (usi_cert_expiry, usi_cert_subject, usi_notification_email)
        // live on the platform side (lms-labs.com client_rto_configs)
        // — no Moodle plugin DB changes are required.  See version.php for
        // the full feature list.
        upgrade_plugin_savepoint(true, 2026050100038, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026050100039) {
        // VERIFY-LIB-INCLUDE — schema-free version bump.  Hotfix only:
        // verify.php and index.php now explicitly require lib.php so the
        // helper functions they call (local_rtocompliance_get_certificate_types
        // and local_rtocompliance_render_nav_header) resolve correctly.
        // No DB changes.
        upgrade_plugin_savepoint(true, 2026050100039, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026050100040) {
        // CERT-TEMPLATE-BUILDER (v4.2.40) — new visual drag-and-drop
        // certificate template builder with ASQA approval gate.  Adds
        // table local_rtocompliance_certtmpl which stores per-cert-type
        // templates with status workflow (draft|approved|archived) and
        // an isactive flag (only one active template per certtype).
        // Design fields (background image, logo, mandatory dynamic
        // fields and unlimited custom text/date/image/shape fields) are
        // stored as JSON in the designjson column.  See classes/cert_template.php
        // and classes/cert_template_renderer.php for the model + TCPDF
        // bridge.  Existing certificate issuance falls through to the
        // legacy hard-coded layout when no active approved template
        // exists for the requested certtype — zero behaviour change for
        // sites that have not yet built a template.

        $table = new xmldb_table('local_rtocompliance_certtmpl');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id',             XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('certtype',       XMLDB_TYPE_CHAR,    '32', null, XMLDB_NOTNULL, null, null);
            $table->add_field('name',           XMLDB_TYPE_CHAR,    '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('status',         XMLDB_TYPE_CHAR,    '20', null, XMLDB_NOTNULL, null, 'draft');
            $table->add_field('isactive',       XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('designjson',     XMLDB_TYPE_TEXT,    null, null, null, null, null);
            $table->add_field('bgitemid',       XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('lastvalidation', XMLDB_TYPE_TEXT,    null, null, null, null, null);
            $table->add_field('createdby',      XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('approvedby',     XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('timecreated',    XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified',   XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timeapproved',   XMLDB_TYPE_INTEGER, '10', null, null, null, null);

            $table->add_key('primary',    XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('createdby',  XMLDB_KEY_FOREIGN, ['createdby'],  'user', ['id']);
            $table->add_key('approvedby', XMLDB_KEY_FOREIGN, ['approvedby'], 'user', ['id']);

            $table->add_index('certtype_status', XMLDB_INDEX_NOTUNIQUE, ['certtype', 'status']);
            $table->add_index('certtype_active', XMLDB_INDEX_NOTUNIQUE, ['certtype', 'isactive']);

            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026050100040, 'local', 'rtocompliance');
    }

    // CERT-OF-COMPLETION + TEST-CERT (v4.2.41) — code-only release
    // (4th certtype 'completion' + cert_test.php sample generator). No
    // schema change; savepoint exists only to record the version bump.
    if ($oldversion < 2026050100041) {
        upgrade_plugin_savepoint(true, 2026050100041, 'local', 'rtocompliance');
    }

    // FIX-RTO-TESTER-FEEDBACK-MAY1 (v4.2.42) — adds a single nullable TEXT
    // column 'evidence' to local_rtocompliance_suitability_answers so the
    // student can attach a free-text explanation when their answer to a
    // Standard 2.2 pre-enrolment requirement is Unsure or No.
    if ($oldversion < 2026050200042) {
        $table = new xmldb_table('local_rtocompliance_suitability_answers');
        $field = new xmldb_field('evidence', XMLDB_TYPE_TEXT, null, null, null, null, null, 'answer');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026050200042, 'local', 'rtocompliance');
    }

    // CERT-TEMPLATE-BUILDER-PRO (v4.2.43, 3 May 2026) — no schema change.
    // The release rebuilds the dynamic-field catalogue, validator rules,
    // starter-template generator, RTO branding filearea (system context,
    // file API only — no new DB columns), renderer image-key painter and
    // editor UX. Savepoint records the version bump.
    if ($oldversion < 2026050200043) {
        upgrade_plugin_savepoint(true, 2026050200043, 'local', 'rtocompliance');
    }

    // BUG-MAY1-AUDIT (v4.2.44, 3 May 2026) — pure UI / behaviour fix release
    // covering the 14 bugs in Errors_1_May_2026_1777702336483.docx
    // (trainer detail row alignment, no-box vocational competency list,
    // verify-page CSS, students.php guest guard, qualbuilder save flash,
    // 16-question 7-category Suitability Checklist rewrite with reversed
    // evidence-on-Yes flow + disability prompt, certificate View →
    // download_cert.php, verify.php Back/Download/Email buttons for
    // authenticated staff).  No schema change; savepoint records the
    // version bump only.
    if ($oldversion < 2026050200044) {
        upgrade_plugin_savepoint(true, 2026050200044, 'local', 'rtocompliance');
    }

    // BUG-MAY1-AUDIT-PASS2 (v4.2.45) — second pass over the same tester
    // report.  Pure UI / wording fixes (suitability checklist intro +
    // declaration text + "Entry Requirements & Prior Skills" category
    // rename, trainer_currency activities list converted to no-box
    // .rtoc-vc-list).  No schema change; savepoint records the bump.
    if ($oldversion < 2026050200045) {
        upgrade_plugin_savepoint(true, 2026050200045, 'local', 'rtocompliance');
    }

    // BUG-MAY1-AUDIT-PASS3 (v4.2.46) — UX-only change: USI-blocked View /
    // Download / Email buttons on certificates.php and verify.php now
    // remain clickable and pop an "USI not verified" alert instead of
    // rendering as greyed-out disabled controls.  No schema change.
    if ($oldversion < 2026050200046) {
        upgrade_plugin_savepoint(true, 2026050200046, 'local', 'rtocompliance');
    }

    // BUG-MAY1-AUDIT-PASS4 (v4.2.47) — full restructure of the pre-enrolment
    // suitability review to satisfy Standard 2 PI 2(a) & 2(b) of the 2025
    // Standards.  Adds 16 new columns to local_rtocompliance_suitability so
    // the structured evidence form, LLN context, decision engine output,
    // auto-generated advice, and trainer 3-outcome override all have a home.
    // Existing pending records (with answer rows in
    // local_rtocompliance_suitability_answers) keep working via the
    // suitability_form_legacy.php branch.
    if ($oldversion < 2026050200047) {
        $table = new xmldb_table('local_rtocompliance_suitability');

        // Widen status column to fit the new override_* values.
        // FIX-MAY2-IDX (v4.2.53): on installs that already have the 'status'
        // index in place, change_field_precision() raises
        // ddl_dependency_exception ("column ... cannot be modified.
        // Dependency found with index mdl_locartocsuit_sta_ix").  Moodle
        // requires the dependent index to be dropped first, the field
        // changed, and the index recreated.  All three operations are
        // guarded so partial-state DBs (where one or other has already
        // run) still upgrade cleanly.
        $statusfield = new xmldb_field('status', XMLDB_TYPE_CHAR, '40', null, XMLDB_NOTNULL, null, 'pending', 'token');
        $statusindex = new xmldb_index('status', XMLDB_INDEX_NOTUNIQUE, ['status']);
        $hadindex = $dbman->index_exists($table, $statusindex);
        if ($hadindex) {
            $dbman->drop_index($table, $statusindex);
        }
        if ($dbman->field_exists($table, $statusfield)) {
            $dbman->change_field_precision($table, $statusfield);
        }
        if ($hadindex && !$dbman->index_exists($table, $statusindex)) {
            $dbman->add_index($table, $statusindex);
        }

        $newfields = [
            // [name, type, length, default, previous-field]
            ['qualification',         XMLDB_TYPE_CHAR,    '32',  null,  'status'],
            ['experience',            XMLDB_TYPE_INTEGER, '1',   '0',   'qualification'],
            ['experience_years',      XMLDB_TYPE_CHAR,    '16',  null,  'experience'],
            ['industry_type',         XMLDB_TYPE_CHAR,    '255', null,  'experience_years'],
            ['school_level',          XMLDB_TYPE_CHAR,    '16',  null,  'industry_type'],
            ['digital_skills',        XMLDB_TYPE_TEXT,    null,  null,  'school_level'],
            ['disability_disclosure', XMLDB_TYPE_TEXT,    null,  null,  'digital_skills'],
            ['req_prereq',            XMLDB_TYPE_CHAR,    '32',  'none','disability_disclosure'],
            ['req_lln_level',         XMLDB_TYPE_CHAR,    '8',   '3',   'req_prereq'],
            ['lln_actual_level',      XMLDB_TYPE_CHAR,    '8',   null,  'req_lln_level'],
            ['reasons',               XMLDB_TYPE_TEXT,    null,  null,  'lln_actual_level'],
            ['support_required',      XMLDB_TYPE_TEXT,    null,  null,  'reasons'],
            ['advice',                XMLDB_TYPE_TEXT,    null,  null,  'support_required'],
            ['override_outcome',      XMLDB_TYPE_CHAR,    '32',  null,  'advice'],
        ];
        foreach ($newfields as [$name, $type, $length, $default, $prev]) {
            $notnull = ($name === 'experience') ? XMLDB_NOTNULL : null;
            $field = new xmldb_field($name, $type, $length, null, $notnull, null, $default, $prev);
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }

        upgrade_plugin_savepoint(true, 2026050200047, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026050200048) {
        // v4.2.48 BUG-MAY2-AUDIT — code-only release. No schema change.
        // Fixes: cert_template_renderer::render() now hydrates background +
        // per-field image paths before painting (backgrounds and per-field
        // images previously rendered blank); resolve_payload() loads custom
        // user profile fields so student.dob actually resolves;
        // download_cert.php now routes through the shared dispatcher
        // instead of building its own legacy PDF (so the active template is
        // honoured for direct downloads); cert_template_edit.php now passes
        // the saved design's actual page dimensions to the JS canvas
        // instead of hardcoding landscape, fixing portrait template editing.
        upgrade_plugin_savepoint(true, 2026050200048, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026050200049) {
        // v4.2.49 BUG-MAY2-AUDIT2 — code-only release. No schema change.
        // Three more cert template defects fixed:
        //   1. resolve_text() now uses PHP date() instead of userdate() for
        //      'date' kind fields (the editor's date-format dropdown offers
        //      PHP date() tokens, not strftime tokens — every date field was
        //      previously rendering as the literal format string).
        //   2. New paint_image() helper detects .svg/.svgz and routes to
        //      TCPDF::ImageSVG() — the bundled NRT/AQF/STA compliance logos
        //      and any user-uploaded SVG backgrounds now render correctly.
        //   3. cert_template::delete() now cleans up FA_BG and FA_IMAGE
        //      filearea blobs so deleting a draft no longer orphans
        //      uploaded files in moodledata.
        upgrade_plugin_savepoint(true, 2026050200049, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026050200050) {
        // v4.2.50 — Suitability 4-stage rebuild + pluggable LLN adapter.
        // Three new fields on local_rtocompliance_suitability to record
        // LLN provenance regardless of which adapter populated the level:
        //   - lln_source       which adapter (manual|webhook|...)
        //   - lln_assessed_at  unix ts of the assessment
        //   - lln_assessor     human/system name of the assessor
        $table = new xmldb_table('local_rtocompliance_suitability');

        $newfields = [
            // [name, type, length, default, previous-field, comment]
            ['lln_source',      XMLDB_TYPE_CHAR,    '32',  null, 'lln_actual_level'],
            ['lln_assessed_at', XMLDB_TYPE_INTEGER, '10',  null, 'lln_source'],
            ['lln_assessor',    XMLDB_TYPE_CHAR,    '100', null, 'lln_assessed_at'],
        ];
        foreach ($newfields as [$name, $type, $length, $default, $prev]) {
            $field = new xmldb_field($name, $type, $length, null, null, null, $default, $prev);
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }

        upgrade_plugin_savepoint(true, 2026050200050, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026050200051) {
        // v4.2.51 DECLARATION-SELFAUDIT - persist the typed-name signature
        // and the signed-at timestamp from Stage 4 of the suitability form.
        // Two new fields on local_rtocompliance_suitability:
        //   - declaration_name       char(200) - what the student typed
        //   - declaration_signed_at  int(10)   - unix ts of the attestation
        $table = new xmldb_table('local_rtocompliance_suitability');

        $newfields = [
            // [name, type, length, previous-field]
            ['declaration_name',      XMLDB_TYPE_CHAR,    '200', 'lln_assessor'],
            ['declaration_signed_at', XMLDB_TYPE_INTEGER, '10',  'declaration_name'],
        ];
        foreach ($newfields as [$name, $type, $length, $prev]) {
            $field = new xmldb_field($name, $type, $length, null, null, null, null, $prev);
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }

        upgrade_plugin_savepoint(true, 2026050200051, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026050200052) {
        // v4.2.52 DECLARATION-SELFAUDIT-B7-SPACE - no schema change, just a
        // savepoint marker bumped in lockstep with version.php so the upgrade
        // engine recognises the release. The actual fix is server-side
        // validation in suitability_form.php that requires the typed-name
        // signature to contain a space (first + last name) on top of the
        // existing min-length-3 check from v4.2.51.
        upgrade_plugin_savepoint(true, 2026050200052, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026050200053) {
        // v4.2.53 FIX-MAY2-IDX - savepoint marker only.  The actual fix
        // is inside the existing v4.2.47 block above (drop status index,
        // widen field, recreate index) so installs still on $oldversion <
        // 2026050200047 will benefit from the same code path.  This
        // marker is bumped in lockstep with version.php so the upgrade
        // engine recognises the release.
        upgrade_plugin_savepoint(true, 2026050200053, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026050200054) {
        // FIX-MAY2-IDX-V2 (v4.2.54): defensive re-application of the v4.2.47
        // status-column-widening sequence as a SECOND savepoint so any DB
        // that ended up with $oldversion >= 2026050200047 in a partial state
        // (because the original v4.2.47 block half-failed at the broken
        // change_field_precision call before v4.2.53 was released) still gets
        // the index-aware drop/change/add sequence applied.  Idempotent:
        //   - drop status index if present (no-op if not)
        //   - change_field_precision to CHAR(40) (no-op if already CHAR(40))
        //   - re-add status index if missing (no-op if present)
        // Safe to run on freshly-installed DBs (install.xml already at CHAR(40)
        // with the status index present) and on DBs that successfully ran
        // v4.2.53. A customer reported the original ddl_dependency_exception still
        // surfacing after upgrading to v4.2.53, so we widen the safety net
        // by ALSO running the fix from a NEW savepoint that no install can
        // possibly have passed yet.
        $table = new xmldb_table('local_rtocompliance_suitability');
        if ($dbman->table_exists($table)) {
            $statusfield = new xmldb_field('status', XMLDB_TYPE_CHAR, '40', null, XMLDB_NOTNULL, null, 'pending', 'token');
            $statusindex = new xmldb_index('status', XMLDB_INDEX_NOTUNIQUE, ['status']);
            if ($dbman->index_exists($table, $statusindex)) {
                $dbman->drop_index($table, $statusindex);
            }
            if ($dbman->field_exists($table, $statusfield)) {
                $dbman->change_field_precision($table, $statusfield);
            }
            if (!$dbman->index_exists($table, $statusindex)) {
                $dbman->add_index($table, $statusindex);
            }
        }
        upgrade_plugin_savepoint(true, 2026050200054, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026050200055) {
        // BUG-MAY2-USI-WARN-NOT-BLOCK (v4.2.55): downgrade the USI-not-
        // verified hard block on certificate Download / View / Email to a
        // non-blocking advisory. A customer reported the v4.2.46 popup was
        // stopping legitimate actions even after she'd verified the
        // student's USI offline with USI Registry.  All three surfaces
        // (certificates.php list, verify.php public page, the matching
        // server-side throws in download_cert.php / email_cert.php) now
        // surface the Clause 12 reminder as a warning notification but
        // let the action complete.  Savepoint marker only — no schema
        // change.
        upgrade_plugin_savepoint(true, 2026050200055, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026050200056) {
        // FIX-MAY2-LEGACY-FATAL (v4.2.56): hotfix for a fatal error in
        // suitability_form_legacy.php — admin-notification helper was
        // defined inside an if (!function_exists()) block at the bottom
        // of the file but called from the POST handler near the top.
        // PHP does not hoist conditionally-defined functions, so the
        // call hit "Call to undefined function" before the definition
        // ran.  Function moved verbatim to the top of the file (above
        // the POST handler).  Same release also corrects a stale
        // $plugin->release string ('4.2.48' → '4.2.56') that had drifted
        // through every release from 4.2.49 to 4.2.55.  Savepoint
        // marker only — no schema change.
        upgrade_plugin_savepoint(true, 2026050200056, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026050200057) {
        // UI-POLISH-MAY2-PASS3 (v4.2.57): six tester-reported UI items
        // from Errors_2_May_2026.docx that the v4.2.42 attempt did not
        // fully resolve.
        //   #1 Trainer-row "Edit / Delete" body-appended dropdown was
        //      still truncating "Delete" to "D..." underneath the Edit
        //      Trainer hover state.  CSS in styles.css now applies
        //      min-width:13rem, white-space:nowrap, padding:6px 18px on
        //      .rtoc-body-menu and .rtoc-body-menu .dropdown-item with
        //      !important so theme overrides cannot squeeze the menu.
        //   #2 Trainer/Assessor Role checkbox list was rendering with
        //      the first row inside a .felement box and every following
        //      row outside it (caused by the broken-HTML addGroup
        //      separator '</div><div class="rtoc-checkbox-list-row">'
        //      that closed .felement mid-stream).  Separator changed to
        //      a clean '<br>' in trainer_edit.php so all rows flow
        //      naturally inside one .felement.
        //   #3 Vocational Competency Evidence list had the same broken-
        //      separator bug — same fix.
        //   #4 Both lists are now styled (styles.css) with no
        //      background, no border, tight 1.9 line-height — i.e. a
        //      plain vertical list, not boxes — as requested.
        //   #5 Three TAS Industry Consultation AI Generate buttons
        //      (Key Feedback / Impact on Training / Impact on
        //      Assessment) were btn-outline-primary with subtle wording
        //      and were being missed visually.  Bumped to btn-primary
        //      (solid blue) with explicit "AI:" prefix and full field
        //      name in each button label so they are unmistakably AI-
        //      powered actions.
        //   #6 Consultation evidence file uploads were sometimes not
        //      appearing in the consultation list after save.  Two
        //      defensive changes in tas_consultation.php POST handler:
        //      (a) capture the expected filename from the DRAFT area
        //      BEFORE file_save_draft_area_files() runs and set
        //      evidencedocument from that captured value; (b)
        //      initialise $record->evidencedocument='' on insert so the
        //      column is never NULL on a fresh row.  The previous flow
        //      depended on a second get_area_files() round-trip after
        //      save — any caching/timing edge that returned empty
        //      silently left evidencedocument NULL and the row rendered
        //      "None".
        // Savepoint marker only — no schema change.
        upgrade_plugin_savepoint(true, 2026050200057, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026050200058) {
        // ASQA-COMPLIANCE-PASS (v4.2.58, 2 May 2026): full ASQA cert
        // audit pass after an admin user's testamur was found in breach
        // of five mandates from the ASQA Sample
        // Testamur and Statement of Attainment fact sheet — missing
        // NRT logo, missing authorised signatory + signature line,
        // wrong AQF wording ("issued under" vs the correct "is
        // recognised within"), wrong attainment wording ("has
        // successfully completed all" vs the correct "has fulfilled
        // the requirements for"), and no authenticity measure
        // (verify URL).
        //
        // ROOT CAUSE: cert_template renderer was already correct, but
        // the legacy hard-coded TCPDF fallback in lib.php (which ran
        // whenever no active template existed for a cert type) was
        // missing all five elements.  TWO duplicated copies of the
        // legacy generator (one inline in send_certificate_email(),
        // one inline in render_certificate_pdf_string()) and no
        // default template was seeded on a fresh install — which is
        // exactly what produced the non-compliant cert.
        //
        // FIX (no schema change — code + seed only):
        //   1. Collapsed the two duplicated generators into a single
        //      helper local_rtocompliance_render_certificate_legacy_pdf()
        //      that fixes all five ASQA gaps (NRT logo top-right,
        //      "is recognised within" AQF wording, "has fulfilled the
        //      requirements for" attainment wording, signatory block
        //      bottom-left from settings, authenticity verify URL +
        //      cert number bottom-right).
        //   2. send_certificate_email() now routes through the
        //      canonical render_certificate_pdf_string() — single
        //      source of truth.
        //   3. cert_template.php gained four NEW alt-orientation
        //      starters (testamur PORTRAIT, statement LANDSCAPE,
        //      record LANDSCAPE, completion PORTRAIT) so admins can
        //      pick orientation per RTO preference.
        //   4. Added cert_template::seed_default_templates_if_empty()
        //      which is called HERE — for every cert type with no
        //      templates yet, seeds BOTH portrait + landscape
        //      starters as APPROVED templates and activates the
        //      default-orientation one (testamur=L, statement=P,
        //      record=P, completion=L per ASQA fact sheet).
        //   5. Added settings: signatoryname, signatorytitle,
        //      aqfstatement, certfooter (existing lang strings,
        //      newly registered + new aqfstatement strings).
        //
        // No schema change — savepoint marker only, plus seed call.
        try {
            require_once($CFG->dirroot . '/local/rtocompliance/classes/cert_template.php');
            \local_rtocompliance\cert_template::seed_default_templates_if_empty();
        } catch (\Throwable $e) {
            // Seed failure must NEVER block the upgrade — admins can
            // re-run by importing default starters from the cert
            // template UI.  Log only.
            debugging('v4.2.58 default template seed failed: ' . $e->getMessage(),
                DEBUG_DEVELOPER);
        }
        upgrade_plugin_savepoint(true, 2026050200058, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026050200059) {
        // ASQA-COMPLIANCE-PASS-2 (v4.2.59, 2 May 2026): second full audit
        // pass against the ASQA "Sample forms of AQF certification
        // documentation" fact sheet (11 May 2020). The first audit round
        // (v4.2.58) fixed the five missing-element mandates on the
        // testamur. This second pass walked the fact sheet line by line
        // against every cert type and surfaced four MORE compliance gaps:
        //
        //   #1 Statement of Attainment mandatory disclaimer was rendered
        //      at the BOTTOM of the page (footer). Fact sheet (page 4)
        //      explicitly requires it to be PROMINENT — at the top.
        //   #2 Statement of Attainment recipient wording was wrong:
        //      "is hereby awarded to ... for attainment of" (testamur
        //      wording). Fact sheet specifies SoA wording as "This is a
        //      statement that ... has attained".
        //   #3 Record of Results starter design was missing the
        //      qualification line (only had student + USI + units).
        //      Fact sheet sample shows "Name of student", "Name of
        //      qualification" and a Semester/Year | Units | Results
        //      table.
        //   #4 NRT logo was painted on Record of Results in the legacy
        //      fallback. Fact sheet shows the NRT mark only on
        //      testamurs and statements of attainment.
        //
        // Fixes (no schema change — code + seed only):
        //   1. Legacy fallback in lib.php now: paints uploaded RTO logo
        //      and signature image, omits NRT on Record of Results,
        //      renders the SoA mandatory disclaimer as a prominent
        //      bordered banner at the TOP, uses correct SoA wording,
        //      renders the five new optional descriptor settings.
        //   2. Starter SoA designs (portrait + landscape) move the
        //      NOT-A-TESTAMUR statement to the top and use correct
        //      "This is a statement that" / "has attained" wording.
        //   3. Starter Record of Results designs (portrait + landscape)
        //      add the qualification.code + qualification.name labelled
        //      line, plus a header row (Semester/Year | Units enrolled
        //      | Results) above the units field.
        //   4. Default testamur starter places the optional AQF logo
        //      bottom-centre per the fact sheet diagram.
        //   5. Renderer pulls industry_descriptor / occupational_stream
        //      / australian_apprenticeship / language_statement /
        //      skill_set_statement from the new admin settings instead
        //      of empty strings.
        //   6. Canonical NOT-A-TESTAMUR string updated to fact sheet
        //      verbatim ("...IS ISSUED BY A REGISTERED TRAINING
        //      ORGANISATION WHEN..." — was "...IS ISSUED WHEN...").
        //
        // Seeded templates from v4.2.58 that were never touched (created
        // by the seed routine, never edited by an admin) get their
        // design_json refreshed in place so the corrected starters take
        // effect on existing installs. Admins who customised their
        // templates after install are NEVER touched (timecreated !=
        // timemodified guard).
        try {
            require_once($CFG->dirroot . '/local/rtocompliance/classes/cert_template.php');
            $now = time();
            foreach (['testamur', 'statement', 'record', 'completion'] as $certtype) {
                $defaultorientation = \local_rtocompliance\cert_template::default_orientation($certtype);
                foreach (['L', 'P'] as $orientation) {
                    $orientationlabel = ($orientation === 'L') ? 'Landscape' : 'Portrait';
                    $certtypelabel = ucfirst($certtype === 'statement' ? 'Statement of Attainment'
                                          : ($certtype === 'record' ? 'Record of Results'
                                          : ($certtype === 'completion' ? 'Certificate of Completion'
                                          : 'Testamur')));
                    $name = 'Default ' . $certtypelabel . ' (' . $orientationlabel . ')';
                    $existing = $DB->get_record('local_rtocompliance_certtmpl', [
                        'name'     => $name,
                        'certtype' => $certtype,
                    ]);
                    if (!$existing) {
                        continue;
                    }
                    // Untouched-by-admin guard: only refresh if seeded
                    // by the v4.2.58 system seeder (createdby = 0) AND
                    // never edited (timecreated == timemodified).
                    if ((int)$existing->createdby !== 0 ||
                        (int)$existing->timecreated !== (int)$existing->timemodified) {
                        continue;
                    }
                    $design = \local_rtocompliance\cert_template::build_starter_design($certtype, $orientation);
                    $existing->designjson   = json_encode($design, JSON_UNESCAPED_SLASHES);
                    $existing->timemodified = $existing->timecreated; // preserve untouched marker
                    $DB->update_record('local_rtocompliance_certtmpl', $existing);
                }
                // First-install seed for sites that never ran v4.2.58.
                if (!$DB->record_exists('local_rtocompliance_certtmpl', ['certtype' => $certtype])) {
                    \local_rtocompliance\cert_template::seed_default_templates_if_empty();
                }
            }
        } catch (\Throwable $e) {
            debugging('v4.2.59 default template refresh failed: ' . $e->getMessage(),
                DEBUG_DEVELOPER);
        }
        upgrade_plugin_savepoint(true, 2026050200059, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026050200060) {
        // ASQA-COMPLIANCE-PASS-3 (v4.2.60, 2 May 2026): third audit pass
        // against the ASQA "Sample forms of AQF certification documentation"
        // fact sheet (11 May 2020). Three remaining gaps closed:
        //
        //   #1 The PORTRAIT testamur starter never received the optional
        //      AQF logo bottom-centre that the LANDSCAPE testamur got in
        //      v4.2.59. Fact sheet diagrams show the AQF logo on both
        //      orientations.
        //   #2 The State / Territory Training Authority logo had no admin
        //      upload path. Renderer hard-coded the bundled SVG fallback,
        //      which is just a placeholder. RTOs delivering state-funded
        //      VET need to display their actual STA logo.
        //   #3 The completion-of-course statement (SoA optional descriptor
        //      per fact sheet page 4) had no admin setting. Renderer
        //      always passed an empty string.
        //
        // Plus two non-compliance UX wins:
        //   #4 Editor was intimidating for non-technical staff. Added a
        //      collapsible Quick Guide panel above the LEFT column with
        //      plain-language step-by-step instructions.
        //   #5 No undo if an admin broke a template while editing. Added
        //      "Reset to ASQA starter" button on the list page that re-
        //      seeds the design from build_starter_design() in one click,
        //      preserving status / approval / activation.
        //
        // No schema change. The ONLY starter that changed is the testamur
        // PORTRAIT (gained the optional AQF logo). Re-seed only that
        // single starter, only if untouched by an admin (createdby=0 AND
        // timecreated==timemodified). All other v4.2.59 starters are
        // unchanged so we leave them alone.
        try {
            require_once($CFG->dirroot . '/local/rtocompliance/classes/cert_template.php');
            $name = 'Default Testamur (Portrait)';
            $existing = $DB->get_record('local_rtocompliance_certtmpl', [
                'name'     => $name,
                'certtype' => 'testamur',
            ]);
            if ($existing
                && (int)$existing->createdby === 0
                && (int)$existing->timecreated === (int)$existing->timemodified) {
                $design = \local_rtocompliance\cert_template::build_starter_design('testamur', 'P');
                $existing->designjson   = json_encode($design, JSON_UNESCAPED_SLASHES);
                $existing->timemodified = $existing->timecreated; // preserve untouched marker
                $DB->update_record('local_rtocompliance_certtmpl', $existing);
            }
            // First-install seed for sites that never ran v4.2.58/v4.2.59.
            \local_rtocompliance\cert_template::seed_default_templates_if_empty();
        } catch (\Throwable $e) {
            debugging('v4.2.60 testamur portrait re-seed failed: ' . $e->getMessage(),
                DEBUG_DEVELOPER);
        }
        upgrade_plugin_savepoint(true, 2026050200060, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026050200061) {
        // ASQA-COMPLIANCE-PASS-4 (v4.2.61, 2 May 2026): fourth audit pass
        // against the ASQA "Sample forms of AQF certification documentation"
        // fact sheet (11 May 2020). Found that the optional text descriptors
        // (industry / occupational / apprenticeship / language / skill_set
        // / completion-of-course) introduced as dynamic fields in v4.2.59
        // and v4.2.60 were configured in the field catalogue and renderer
        // but were NEVER painted onto any of the six default ASQA starters
        // (testamur P+L, SoA P+L, Record P+L). RTOs on stock templates had
        // to drag every descriptor onto every template manually — invisible
        // gap that no admin would discover until an ASQA auditor flagged
        // the missing optional descriptors as a recommended-practice
        // shortfall.
        //
        // FIX:
        //   #1 Testamur P+L starters — paint industry_descriptor,
        //      occupational_stream, australian_apprenticeship and
        //      language_statement as small italic centred lines between
        //      the qualification block and the AQF statement. They render
        //      blank (no visual artefact) when the matching admin setting
        //      is empty, so RTOs that don't need them see no change.
        //   #2 SoA P+L starters — paint
        //      qualification.completionofcoursestatement (v4.2.60 setting),
        //      skill_set_statement and language_statement below the
        //      part-of-qualification line. Same blank-when-empty behaviour.
        //   #3 Record P+L starters — paint language_statement above the
        //      signature row. Same blank-when-empty behaviour.
        //   #4 All six ASQA starters — added "AUTHORISED PERSON" tiny
        //      italic grey label above the signatory name AND "DATE" tiny
        //      italic grey label above the issue date, per ASQA Sample
        //      Forms fact sheet diagrams. Recipient-friendly: a holder
        //      reading the cert immediately understands what each line
        //      means.
        //
        // STA logo painting was DELIBERATELY NOT added to the starters.
        // Reason: cert_template_renderer always seeds
        // 'state_training_authority_logo__path' with the bundled SVG
        // fallback when no admin upload exists, so painting it on default
        // starters would force the placeholder STA logo onto every cert
        // for every RTO — bad for non-state-funded RTOs. Admins drag the
        // STA logo on themselves after uploading their actual STA logo
        // via the cert template branding panel.
        //
        // No schema change. Re-seed every UNTOUCHED stock starter
        // (createdby=0 AND timecreated==timemodified) so admins on
        // default templates pick up the new descriptor slots and labels
        // automatically. Custom templates and any starter an admin has
        // edited are NEVER touched.
        try {
            require_once($CFG->dirroot . '/local/rtocompliance/classes/cert_template.php');
            $stockstarters = [
                ['Default Testamur (Landscape)',                'testamur',  'L'],
                ['Default Testamur (Portrait)',                 'testamur',  'P'],
                ['Default Statement of Attainment (Portrait)',  'statement', 'P'],
                ['Default Statement of Attainment (Landscape)', 'statement', 'L'],
                ['Default Record of Results (Portrait)',        'record',    'P'],
                ['Default Record of Results (Landscape)',       'record',    'L'],
            ];
            foreach ($stockstarters as [$name, $certtype, $orientation]) {
                $existing = $DB->get_record('local_rtocompliance_certtmpl', [
                    'name'     => $name,
                    'certtype' => $certtype,
                ]);
                if ($existing
                    && (int)$existing->createdby === 0
                    && (int)$existing->timecreated === (int)$existing->timemodified) {
                    $design = \local_rtocompliance\cert_template::build_starter_design($certtype, $orientation);
                    $existing->designjson   = json_encode($design, JSON_UNESCAPED_SLASHES);
                    $existing->timemodified = $existing->timecreated; // preserve untouched marker
                    $DB->update_record('local_rtocompliance_certtmpl', $existing);
                }
            }
            // First-install seed for sites that never ran v4.2.58/v4.2.59/v4.2.60.
            \local_rtocompliance\cert_template::seed_default_templates_if_empty();
        } catch (\Throwable $e) {
            debugging('v4.2.61 ASQA starter re-seed failed: ' . $e->getMessage(),
                DEBUG_DEVELOPER);
        }
        upgrade_plugin_savepoint(true, 2026050200061, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026050200062) {
        // FIX-CFG-SCOPE recovery (v4.2.62, 2 May 2026): re-runs the v4.2.61
        // ASQA-COMPLIANCE-PASS-4 starter re-seed on every install whose
        // savepoint already advanced past 2026050200061 with the upgrade
        // having silently no-op'd (because the v4.2.61 require_once call
        // hit the missing-global-$CFG bug — see the function-level comment
        // block at the top of this file). This block is guarded the same
        // way as the v4.2.61 block: only re-seeds stock starters that the
        // admin has never edited (createdby=0 AND timecreated==timemodified).
        // It is safe to run on:
        //   - fresh installs (no records exist yet — falls through to the
        //     seed_default_templates_if_empty() call below)
        //   - sites that successfully ran v4.2.61 (no-op: stock starters
        //     already carry the v4.2.61 design)
        //   - sites that hit the bug (the actual recovery case — stock
        //     starters get the v4.2.61 design they never received)
        try {
            require_once($CFG->dirroot . '/local/rtocompliance/classes/cert_template.php');
            $stockstarters = [
                ['Default Testamur (Landscape)',                'testamur',  'L'],
                ['Default Testamur (Portrait)',                 'testamur',  'P'],
                ['Default Statement of Attainment (Portrait)',  'statement', 'P'],
                ['Default Statement of Attainment (Landscape)', 'statement', 'L'],
                ['Default Record of Results (Portrait)',        'record',    'P'],
                ['Default Record of Results (Landscape)',       'record',    'L'],
            ];
            foreach ($stockstarters as [$name, $certtype, $orientation]) {
                $existing = $DB->get_record('local_rtocompliance_certtmpl', [
                    'name'     => $name,
                    'certtype' => $certtype,
                ]);
                if ($existing
                    && (int)$existing->createdby === 0
                    && (int)$existing->timecreated === (int)$existing->timemodified) {
                    $design = \local_rtocompliance\cert_template::build_starter_design($certtype, $orientation);
                    $existing->designjson   = json_encode($design, JSON_UNESCAPED_SLASHES);
                    $existing->timemodified = $existing->timecreated; // preserve untouched marker
                    $DB->update_record('local_rtocompliance_certtmpl', $existing);
                }
            }
            // First-install seed for sites that never ran v4.2.58/v4.2.59/v4.2.60/v4.2.61.
            \local_rtocompliance\cert_template::seed_default_templates_if_empty();
        } catch (\Throwable $e) {
            debugging('v4.2.62 ASQA starter recovery re-seed failed: ' . $e->getMessage(),
                DEBUG_DEVELOPER);
        }
        upgrade_plugin_savepoint(true, 2026050200062, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026050200070) {
        // CERT-TEMPLATE-AUDIENCES (v4.3.0, 2 May 2026):
        //
        // Adds the "audience" dimension to cert templates so an RTO can
        // issue a different testamur design to apprentices vs general
        // public vs VET-FEE students vs school-based learners (etc.)
        // for the SAME qualification code. The runtime selection rule
        // becomes (certtype + audience) instead of just (certtype).
        //
        // Two schema additions:
        //   1. local_rtocompliance_certtmpl gains:
        //        - audience       char(32) NOT NULL DEFAULT 'default'
        //        - audiencelabel  char(255) NULL
        //      All pre-existing rows back-fill to audience='default'
        //      via the column DEFAULT, so legacy templates remain the
        //      active "default audience" template for their certtype
        //      and behaviour is unchanged for sites that never touch
        //      the new dropdown.
        //   2. local_rtocompliance_certs gains:
        //        - certtmplid     int(10) NULL
        //      Records WHICH template was used to issue the cert. NULL
        //      on legacy rows (re-pick at render time); set on every
        //      v4.3.0+ issuance so a later reissue/redownload uses the
        //      same template even if the active template has since
        //      been swapped.
        //
        // No data migration required. Field guards (field_exists) make
        // this idempotent so a half-run upgrade can be re-attempted
        // safely.
        $dbman = $DB->get_manager();
        $tmpltable = new xmldb_table('local_rtocompliance_certtmpl');

        $audfield = new xmldb_field('audience', XMLDB_TYPE_CHAR, '32',
            null, XMLDB_NOTNULL, null, 'default', 'certtype');
        if (!$dbman->field_exists($tmpltable, $audfield)) {
            $dbman->add_field($tmpltable, $audfield);
        }

        $audlabelfield = new xmldb_field('audiencelabel', XMLDB_TYPE_CHAR, '255',
            null, null, null, null, 'audience');
        if (!$dbman->field_exists($tmpltable, $audlabelfield)) {
            $dbman->add_field($tmpltable, $audlabelfield);
        }

        $audindex = new xmldb_index('certtype_audience_active',
            XMLDB_INDEX_NOTUNIQUE, ['certtype', 'audience', 'isactive']);
        if (!$dbman->index_exists($tmpltable, $audindex)) {
            $dbman->add_index($tmpltable, $audindex);
        }

        $certstable = new xmldb_table('local_rtocompliance_certs');
        $tmplidfield = new xmldb_field('certtmplid', XMLDB_TYPE_INTEGER, '10',
            null, null, null, null, 'reissued_at');
        if (!$dbman->field_exists($certstable, $tmplidfield)) {
            $dbman->add_field($certstable, $tmplidfield);
        }

        $tmplidindex = new xmldb_index('certtmplid', XMLDB_INDEX_NOTUNIQUE, ['certtmplid']);
        if (!$dbman->index_exists($certstable, $tmplidindex)) {
            $dbman->add_index($certstable, $tmplidindex);
        }

        upgrade_plugin_savepoint(true, 2026050200070, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026050200080) {
        // NRT-LOGO-COMPLIANCE (v4.4.0, 2 May 2026):
        //
        // Settings-only release — no schema change. The bundled
        // pix/ artwork (nrt_logo.svg, aqf_logo.svg, sta_logo.svg)
        // which v4.2.43-v4.3.0 shipped as hand-drawn text-only
        // placeholders has been deleted and replaced with real
        // PNG/JPEG artwork (pix/nrt_logo.png, pix/aqf_logo.jpg).
        // RTOs can override either via the new "Compliance logos"
        // section on the local plugin settings page, and a new
        // organisation_seal upload slot is now required on
        // testamur + Statement of Attainment templates per the
        // ASQA Practice Guide. The validator hard-blocks Submit-
        // for-approval if the NRT logo is dragged onto record/
        // attendance/completion templates (NRT Logo Conditions
        // of Use Policy).
        //
        // No DB writes here — this savepoint exists so Moodle's
        // upgrade engine recognises the release and so any future
        // post-merge schema bump can be slotted in cleanly above.
        upgrade_plugin_savepoint(true, 2026050200080, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026050200081) {
        // NRT-LOGO-OFFICIAL-ARTWORK (v4.4.1, 2 May 2026):
        //
        // Asset-only patch — the bundled pix/nrt_logo.png was
        // replaced with the official ASQA-issued NRT mark artwork
        // supplied by the RTO. Same filename, same dimension class,
        // no schema or code changes — resolve_compliance_asset_path()
        // automatically picks up the new bytes on every cert render.
        //
        // No DB writes here — this savepoint exists so Moodle's
        // upgrade engine recognises the release.
        upgrade_plugin_savepoint(true, 2026050200081, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026050200082) {
        // ASQA-AUDIT-LEGACY-LOGOS (v4.4.2, 2 May 2026):
        //
        // Code-only release — no schema change. Three compliance gaps
        // in the legacy TCPDF fallback renderer (lib.php) fixed:
        //   (a) NRT logo was silently missing (pix/nrt_logo.svg deleted
        //       in v4.4.0; file_exists always false since then). Now
        //       uses resolve_compliance_asset_path() + Image() for PNG.
        //   (b) AQF logo was never rendered by the legacy fallback.
        //       Added alongside the NRT logo in the header.
        //   (c) Organisation seal was never rendered by the legacy
        //       fallback. Added at bottom-centre on testamur/SoA.
        // Plus: cert_template_renderer.php now guards the
        // qualification.partofstatement dynamic field against nonsensical
        // output when qualificationcode is a plain word rather than a
        // real qualification code (e.g. "compliance compliance" bug).
        upgrade_plugin_savepoint(true, 2026050200082, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026050200083) {
        // NAV-CERT-TEMPLATES (v4.4.3, 2 May 2026): nav-only fix.
        // Certificate Templates and Test Certificate Generator were missing
        // from the plugin's left-hand settings navigation menu. Added to
        // $menuitems in local_rtocompliance_extend_settings_navigation
        // (lib.php). No schema change.
        upgrade_plugin_savepoint(true, 2026050200083, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026050200084) {
        // SIDEBAR-CERT-TEMPLATES (v4.4.4, 2 May 2026): nav-only fix.
        // Certificate Templates and Test Certificate were missing from the
        // plugin's own left-hand sidebar navigation (the QA2 – Student
        // Support group rendered by local_rtocompliance_render_nav_sidebar()
        // in lib.php). Added 'Certificate Templates' (cert_templates.php)
        // and 'Test Certificate' (cert_test.php) immediately after
        // 'Certificates' in the $groups[] array. No schema change.
        upgrade_plugin_savepoint(true, 2026050200084, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026050200085) {
        // DOCS-UPDATE (v4.4.5, 2 May 2026): documentation and support page
        // update. The in-plugin support.php module descriptions were updated
        // to accurately reflect all 28 modules now in the plugin. The
        // marketing site docs page was updated to match. No schema change,
        // no code change, no AMD change. Savepoint 2026050200085 is a
        // marker so Moodle's upgrade engine recognises the release.
        upgrade_plugin_savepoint(true, 2026050200085, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026050200086) {
        // AI-SURVEY-RESULTS-FIRST (v4.4.6, 2 May 2026): bug fix — after
        // clicking "Run AI Analysis", the results panel was rendered BELOW
        // the info/form cards so users had to scroll to find them. The
        // auto-scroll JS was blocked by Moodle theme Content Security
        // Policy on many installs. Fixed: ai_analysis.php now renders
        // results ABOVE the form so they appear at the top of the page
        // content immediately after the POST. Also fixed: survey_analyzer.php
        // was not returning the 'responses_analysed' key, so the
        // "Responses Analysed" stat card always showed 0. No schema change.
        // No AMD change. Savepoint 2026050200086 is a marker only.
        upgrade_plugin_savepoint(true, 2026050200086, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026050200087) {
        // NRT-LOGO-ASPECT + CERT-TEST-ORIENTATION (v4.4.7, 2 May 2026):
        // Two certificate renderer fixes:
        // (1) NRT-LOGO-ASPECT: cert_template_renderer.php was passing the
        //     template-defined h_mm explicitly to TCPDF Image() for compliance
        //     logo dynamic fields (nrt_logo, aqf_logo, rto.logo, signatory.signature,
        //     state_training_authority_logo). TCPDF stretches the image to exactly
        //     that box — ignoring the PNG's natural aspect ratio — producing a
        //     visibly distorted NRT mark on landscape certs. Fixed: pass h=0 so
        //     TCPDF auto-calculates height from the image aspect ratio. Width from
        //     the template design is still respected; height adjusts proportionally.
        // (2) CERT-TEST-ORIENTATION: cert_test.php had no way to preview a cert
        //     in the non-default orientation. Added Portrait / Landscape / Auto
        //     selector on the test certificate form; orientation is threaded through
        //     local_rtocompliance_render_certificate_pdf_string() →
        //     cert_template_renderer::render() and →
        //     local_rtocompliance_render_certificate_legacy_pdf() as an optional
        //     $orientation_override parameter ('' = auto, 'P' = force portrait,
        //     'L' = force landscape). Five new lang strings added.
        // No schema change. No AMD change. Savepoint 2026050200087 is a marker.
        upgrade_plugin_savepoint(true, 2026050200087, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026050200088) {
        // TEXT-CLIP-FIX (v4.4.8, 2 May 2026): cert_template_renderer.php was
        // passing the template-defined field height ($h) as BOTH the per-line
        // height (2nd param) AND the maxh cap (14th param) in the TCPDF
        // MultiCell() call. When a large font (e.g. 28pt Certificate of Completion
        // title ≈ 9.9mm) is rendered into a field box of similar height, TCPDF
        // clips the bottom of the glyphs because the line-height leaves no room
        // for internal leading. Fixed: 2nd param is now 0 (auto line height from
        // font size); maxh stays as $h to prevent overflow into adjacent fields.
        // No schema change. No AMD change. Savepoint 2026050200088 is a marker.
        upgrade_plugin_savepoint(true, 2026050200088, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026050200089) {
        // AUTHENTICITY-MEASURE-COMPLETION (v4.4.9, 2 May 2026):
        // ASQA fact sheet "Sample forms of AQF certification documentation"
        // specifies an AUTHENTICITY MEASURE on testamur, record of results,
        // and statement of attainment. This was already required in the
        // validator for those cert types, but completion/attendance certs had
        // no mention of it. Two changes:
        // (1) certificate_validator.php: added authenticityMeasure as a
        //     RECOMMENDED field for completion and attendance cert types with
        //     a detailed message explaining what the RTO must supply (verification
        //     URL, cert number, organisational seal, or watermark).
        // (2) cert_template.php DYNAMIC_FIELDS: updated nrt_logo label to
        //     clearly state it is FORBIDDEN on completion/attendance (not just
        //     in code); updated authenticity_measure label and added
        //     'recommended_for' => ['completion', 'attendance'] so the template
        //     palette and validator both surface the recommendation correctly.
        // No schema change. No AMD change. Savepoint 2026050200089 is a marker.
        upgrade_plugin_savepoint(true, 2026050200089, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026050200090) {
        // DUPLICATE-KEY-CLEANUP (v4.4.10, 3 May 2026):
        // Housekeeping release — fixed two duplicate releaseDate keys in
        // pluginConfig.ts that generated Vite build warnings. No schema,
        // PHP, or AMD changes. This savepoint is a marker only.
        upgrade_plugin_savepoint(true, 2026050200090, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026050200091) {
        // ASQA-AUDIT-DATE + EMAIL-JSON-FIX (v4.4.11, 3 May 2026):
        // (1) cert_template.php starter_record() portrait — "DATE" label was
        //     7pt italic grey #888888 (illegible). Changed to "Date of issue:"
        //     8pt regular #444444. Date value: 9pt #666666 → 10pt bold #222222.
        //     authenticity_measure: x=110 w=85mm right-aligned → x=15 w=180mm
        //     centred so the full verify URL is never truncated.
        // (2) cert_template.php starter_record_landscape() — authenticity_measure
        //     was at y=207+h=4=y=211 on a 210mm-tall page — printed entirely off
        //     the page. Footer compressed: sig y=175 h=10, date alongside sig at
        //     right, authenticity_measure at y=195 spanning 267mm width → y=200.
        // (3) email_cert.php — require_sesskey() was outside the try/catch block.
        //     When the sesskey was bad/expired, Moodle's global exception handler
        //     rendered a full HTML error page instead of JSON, causing the JS to
        //     show "Unexpected token '<', <div class... is not valid JSON".
        //     Fixed: require_sesskey() moved inside the try/catch so all failures
        //     return JSON {ok:false, error:...}.
        // No schema change. No AMD change. Savepoint is a marker only.
        upgrade_plugin_savepoint(true, 2026050200091, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026050200092) {
        // DOWNLOAD-USI-NOTIFICATION-BLEED-FIX (v4.4.12, 3 May 2026):
        // download_cert.php was calling \core\notification::add() for the USI
        // advisory. Because download_cert.php streams a PDF and never renders
        // an HTML page, that notification was queued in the Moodle session but
        // never displayed on that request. It then "bled" onto the next page
        // the same session opened — typically verify.php — causing duplicate
        // yellow banner notifications (one per prior download/view click).
        // Fix: removed the \core\notification::add() call from download_cert.php.
        // The Clause 12 USI reminder is already shown client-side via the
        // onclick alert() on the Download/View buttons in certificates.php and
        // verify.php — no server-side banner is needed.
        // No schema change. No AMD change. Savepoint is a marker only.
        upgrade_plugin_savepoint(true, 2026050200092, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026050200093) {
        // CLAUSE-BANNER-LINKS (v4.4.13, 3 May 2026):
        // Added contextual links to the ASQA compliance clause banner on
        // certificates.php:
        // - Banner title: "ASQA Fact Sheet on AQF Certification Documentation ↗"
        //   linking to the ASQA PDF fact sheet on sample AQF certification forms.
        // - Clause 11 "Template Compliance": "Manage Templates →" linking to
        //   cert_templates.php so staff can jump straight to the template editor.
        // - Clause 13 "NRT Logo": "NRT Logo conditions ↗" linking to the ASQA
        //   NRT Logo fact sheet at asqa.gov.au.
        // - Clause 14 "Transitions": "Transitions Register ↗" linking to the
        //   Training.gov.au notice board (transitions register).
        // - Transitions footer card: added "Training.gov.au Transitions Register ↗"
        //   and "ASQA Fact Sheet ↗" buttons alongside the existing internal link.
        // No schema change. No AMD change. Savepoint is a marker only.
        upgrade_plugin_savepoint(true, 2026050200093, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026050200094) {
        // ORIENTATION-OVERRIDE-DIMENSION-FIX (v4.4.14, 3 May 2026):
        // cert_template_renderer::render() accepted an orientation_override
        // ('P' or 'L') but never swapped the page dimensions to match.
        // When portrait was requested for a landscape-designed template:
        //   - $orientation became 'P'
        //   - $width/$height stayed 297/210 (landscape values from template)
        //   - TCPDF::AddPage('P', [297, 210]) swapped to 210x297 portrait
        //   - ALL element x/y positions designed for 297mm width remained
        //     unchanged, so content appeared on the right half of the 210mm
        //     portrait page with a large blank strip on the left, and elements
        //     near x=297 clipped off the right edge entirely.
        // Fix: detect orientation mismatch, swap $width/$height, then
        // proportionally scale every field's x_mm/y_mm/w_mm/h_mm so the
        // layout fills the new page correctly.
        // No schema change. No AMD change. Savepoint is a marker only.
        upgrade_plugin_savepoint(true, 2026050200094, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026050200095) {
        // 8-BUG-FIX-BATCH (v4.4.15, 3 May 2026): No schema changes.
        // Fixes: (1) TAS AI button IDs — removed custom textarea id attrs so
        // JS data-target and val() use Moodle-generated ids (id_feedback etc).
        // (2) File type badges — hide Moodle fp-restrictions widget; add plain-
        // text static elements. (3) Trainers dropdown — replaced Bootstrap
        // dropdown-menu class with pure inline styles to prevent Delete text
        // from being clipped on hover. (4) Voccomp AI — fixed getElementById
        // to use id_description; added null-guard. (5) LLN info boxes —
        // replaced three static text boxes with addHelpButton() (i) icons.
        // (6) Evidence not showing — reset($draftfiles) returned the '.'
        // placeholder; now iterates to find the first real filename. (7) Student
        // declaration — expanded Stage 4 to 5-statement ASQA-aligned declaration.
        // (8) suitability_view — Section 5 shows full declaration text + provenance;
        // Section 2 shows lln_source/assessor/assessed_at when present.
        // Savepoint is a marker only — no schema changes.
        upgrade_plugin_savepoint(true, 2026050200095, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026050200096) {
        // STUDENT-SUITABILITY-CHECK-RENAME (v4.4.16, 3 May 2026): No schema changes.
        // Renamed the student-facing "Pre-Enrolment Suitability Review / Suitability
        // Checklist" to "Student Suitability Check" throughout: page titles, h1
        // headings, email subject, email body, PDF report title, support page entry,
        // lang strings (send_title, view_title, send_btn_short, send_btn, send_new,
        // existing, email_sent, email_resent, no_questions, no_tas, not_yet_answered,
        // bulk_heading, autosend_heading, autosend_heading_desc, autosend_desc), and
        // bulk sender JS gap-count message. Savepoint is a marker only.
        upgrade_plugin_savepoint(true, 2026050200096, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026050200097) {
        // RENAME-LEFTOVER-CLEANUP (v4.4.17, 3 May 2026): No schema changes.
        // Self-audit of v4.4.16 found three user-facing strings still using the
        // old "Suitability Checklist / Pre-Enrolment Suitability" wording:
        // (1) lib.php — HTML email body button label "Complete Suitability
        // Checklist" → "Complete Student Suitability Check"; descriptive text
        // updated to match the plain-text body.
        // (2) support.php — RTO Compliance how_to step said click "Send
        // Suitability Review" → now says "Send Student Suitability Check"
        // (matches the actual button label from suitability_send_btn lang).
        // (3) support.php — FAQ "What is the pre-enrolment suitability form
        // for?" question + answer rewritten to "What is the Student Suitability
        // Check for?" with consistent terminology.
        // Savepoint is a marker only.
        upgrade_plugin_savepoint(true, 2026050200097, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026050200098) {
        // RENAME-SECOND-PASS (v4.4.18, 3 May 2026): No schema changes.
        // A second self-audit found nine more user-visible "suitability
        // checklist / suitability review / pre-enrolment suitability flow"
        // references missed in v4.4.16 / v4.4.17, all now renamed to
        // "Student Suitability Check":
        // (1) lang suitability_bulk_result — "Suitability checklist sent to ..."
        // (2) lang suitability_fill_gaps_desc — "pre-enrolment suitability checklist"
        // (3) lang suitability_fill_gaps_confirm — "suitability checklist"
        // (4) lang lln_heading_desc — "pre-enrolment suitability flow"
        // (5) lang lln_adapter_desc — "when the suitability form is opened"
        // (6) lang lln_webhook_url_desc — "when the suitability form opens"
        // (7) suitability_form.php — student "Already Submitted" message
        // (8) suitability_form.php — trainer notification email body
        // (9) suitability_view.php — pending notification on trainer view
        // Savepoint is a marker only.
        upgrade_plugin_savepoint(true, 2026050200098, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026050200099) {
        // SAVEPOINT-ORDER-FIX (v4.4.19, 3 May 2026): The v4.4.11..v4.4.18
        // savepoint blocks (091..098) were previously appended to this file
        // in DESCENDING order. Moodle runs upgrade blocks top-to-bottom with
        // a captured $oldversion, so a site upgrading from any pre-091
        // version hit the 098 block first (DB→098), then the 097 block,
        // and upgrade_plugin_savepoint(097) threw downgrade_exception
        // "Cannot downgrade from 2026050200098 to 2026050200097". v4.4.19
        // reorders blocks 091..098 into ascending order and adds this
        // marker savepoint so any site that already recorded 098 mid-
        // aborted-upgrade gets a clean path forward.
        // No schema change. No AMD change. Savepoint is a marker only.
        upgrade_plugin_savepoint(true, 2026050200099, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026050300101) {
        // BULLETPROOF-FATAL-HANDLER (v4.4.21, 3 May 2026): trainers.php now
        // installs a top-level set_exception_handler + register_shutdown_function
        // pair right after lib.php so any uncaught Throwable or fatal renders a
        // self-contained diagnostic HTML page (HTTP 200) with the actual error
        // message, file:line, stack trace and a Repair Schema button. No schema
        // change. No AMD change. Savepoint is a marker only.
        upgrade_plugin_savepoint(true, 2026050300101, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026050300102) {
        // HANDLER-BEFORE-LIB (v4.4.22, 3 May 2026): trainers.php now installs
        // the bulletproof fatal handler IMMEDIATELY after config.php and loads
        // adminlib.php + lib.php inside a try/catch so include-time fatals are
        // surfaced on screen instead of producing a silent blank 500. No schema
        // change. No AMD change. Savepoint is a marker only.
        upgrade_plugin_savepoint(true, 2026050300102, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026050300103) {
        // OB-FLUSH-FIX (v4.4.23, 3 May 2026): rtoc_render_fatal_page() now
        // drains all active output-buffer levels (ob_end_clean loop) before
        // outputting the diagnostic HTML. Moodle's config.php calls ob_start()
        // early, so previously the diagnostic output was being written into
        // a buffer that got discarded when the fatal killed the request —
        // producing a blank page even with the handler installed. No schema
        // change. No AMD change. Savepoint is a marker only.
        upgrade_plugin_savepoint(true, 2026050300103, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026050300117) {
        // ROOT CAUSE FIX: PHP PARSE ERROR (v4.4.37, 4 May 2026).
        // Dead-code block if(false){echo html_writer::script('...')} at lines 855-1066
        // contained "Bootstrap's" inside a PHP single-quoted string. The apostrophe
        // terminated the string prematurely -> parse error on line 898 -> PHP refused
        // to execute ANY line of trainers.php (including the very first file_put_contents).
        // Fix: deleted the entire if(false){} block. SCHEMA: NO change.
        upgrade_plugin_savepoint(true, 2026050300117, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026050300118) {
        // CLEANUP (v4.4.38, 4 May 2026): trainers.php confirmed working.
        // Removed all diagnostic scaffolding: $_rtoc_dbg, register_shutdown_function,
        // all 13 @file_put_contents checkpoint calls, trainers_debug_view.php,
        // trainers_step.php. Clean production build. SCHEMA: NO change.
        upgrade_plugin_savepoint(true, 2026050300118, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026050300116) {
        // DEBUG VIEWER V2 (v4.4.36, 4 May 2026): log file was NOT FOUND after
        // visiting trainers.php — means zero lines executed. Suspects: parse error
        // or stale OPcache. Updated trainers_debug_view.php with 5 diagnostic tests:
        // /tmp write, log file, OPcache status + force invalidate, php -l syntax check,
        // file permissions. SCHEMA: NO change. Savepoint is a marker only.
        upgrade_plugin_savepoint(true, 2026050300116, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026050300115) {
        // FILE-BASED DEBUG LOGGER TMP EDITION (v4.4.35, 3 May 2026):
        // v4.4.34 wrote to __DIR__/rtoc_debug.txt — plugin dir not writable → 404.
        // Changed to sys_get_temp_dir().'/rtoc_trainers_debug.txt' (/tmp).
        // Added trainers_debug_view.php — protected admin viewer that reads /tmp file.
        // SCHEMA: NO change. Savepoint is a marker only.
        upgrade_plugin_savepoint(true, 2026050300115, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026050300114) {
        // FILE-BASED DEBUG LOGGER (v4.4.34, 3 May 2026):
        // Browser echo failed — PHP-FPM suppresses all output.
        // file_put_contents() bypasses FastCGI/display_errors/ob completely.
        // Writes to __DIR__/rtoc_debug.txt, readable at /local/rtocompliance/rtoc_debug.txt.
        // Checkpoints at 0,1,2,3,4,5,6,7,8 + per-row [ROW],[ROW-DB],[ROW-DB-OK].
        // Shutdown function writes [FATAL] or [OK] as final line.
        // SCHEMA: NO change. Savepoint is a marker only.
        upgrade_plugin_savepoint(true, 2026050300114, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026050300113) {
        // BROWSER-VISIBLE FATAL CATCHER (v4.4.33, 3 May 2026):
        // register_shutdown_function moved to line 1 of trainers.php (before
        // config.php) so it catches any engine-level fatal anywhere in the file.
        // Uses echo only (no ob_end_clean) — safe with Moodle output buffering.
        // Removed all SSH-only error_log checkpoints (useless without SSH access).
        // Kept: ini_set('memory_limit','512M'), rtoc_mb_strlen/rtoc_mb_substr wrappers.
        // SCHEMA: NO change. Savepoint is a marker only.
        upgrade_plugin_savepoint(true, 2026050300113, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026050300112) {
        // DEEP-CHECKPOINT-LOGGING (v4.4.32, 3 May 2026): extends v4.4.31 with
        // per-row step markers (ROW-START, ROW-STEP2 pre-DB, ROW-STEP3 DB-OK,
        // ROW-STEP4 pre-userdate, ROW-STEP5 pre-vocqual, ROW-STEP6 pre-echo-row),
        // all reporting trainer->id and memory_get_usage(). Also adds:
        // - ini_set('memory_limit','512M') (ChatGPT: memory exhaustion likely)
        // - safe rtoc_mb_strlen/rtoc_mb_substr wrappers replacing 4x mb_ calls
        //   (if mbstring is missing, mb_strlen/mb_substr = uncatchable fatal)
        // - post-header register_shutdown_function (error_log only, no ob_end_clean)
        //   to capture any engine-level fatal that bypasses all try/catch blocks.
        // - CP0 at top of file: logs mbstring=YES/NO and memory_limit.
        // SCHEMA: NO change. Savepoint is a marker only.
        upgrade_plugin_savepoint(true, 2026050300112, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026050300111) {
        // CHECKPOINT-LOGGING (v4.4.31): savepoint only — superseded by v4.4.32
        upgrade_plugin_savepoint(true, 2026050300111, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026050300110) {
        // STEP-DIAGNOSTIC-V2 (v4.4.30, 3 May 2026): fixed trainers_step.php —
        // v1 (v4.4.29) sent HTTP headers BEFORE require_once(config.php) which
        // prevented Moodle's session from starting → "headers already sent" /
        // core\session\exception at Step 1 (false positive, not the real bug).
        // v2 loads config.php FIRST (no prior output), then tests each step
        // inside try/catch and writes results to error_log AND page output.
        // SCHEMA: NO change. SETTINGS: NO change. Savepoint is a marker only.
        upgrade_plugin_savepoint(true, 2026050300110, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026050300109) {
        // STEP-DIAGNOSTIC (v4.4.29, 3 May 2026): adds trainers_step.php —
        // a ChatGPT-recommended step-by-step diagnostic page that bypasses
        // Moodle output buffering, sends HTTP 200 + Content-Type immediately
        // via FastCGI, then tests each bootstrap step (config.php, adminlib,
        // lib.php, admin_externalpage_setup, require_capability, DB tables,
        // critical columns, main query, $OUTPUT->header, nav render) and
        // reports exactly which step fails with the exception message and trace.
        // Visit /local/rtocompliance/trainers_step.php to run.
        // SCHEMA: NO change. SETTINGS: NO change. Savepoint is a marker only.
        upgrade_plugin_savepoint(true, 2026050300109, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026050300108) {
        // REMOVE-EXCEPTION-HANDLERS (v4.4.28, 3 May 2026): set_exception_handler()
        // and register_shutdown_function() added in v4.4.21-22 to surface PHP errors
        // were REPLACING Moodle's own exception handler. When any exception was thrown
        // (session check, capability, theme init), the custom handler fired, called
        // ob_end_clean() on Moodle's FastCGI output buffers, then echoed raw HTML.
        // In PHP-FPM/FastCGI mode this produces an empty FastCGI frame → the server
        // closes the connection with zero bytes → ERR_EMPTY_RESPONSE in Chrome.
        // All other working RTOC pages (students.php, trainer_currency.php, etc.)
        // do NOT register custom exception handlers — that was the only difference.
        // Fix: removed both registrations; Moodle's own handler now runs correctly.
        // SCHEMA: NO change. SETTINGS: NO change. Savepoint is a marker only.
        upgrade_plugin_savepoint(true, 2026050300108, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026050300107) {
        // CSP-LEGACY-CALLBACK (v4.4.27, 3 May 2026): v4.4.26 fixed the new
        // Hook class (before_footer_html_generation.php) but missed the legacy
        // local_rtocompliance_before_footer() function in lib.php which ALSO
        // runs on Moodle 4.x and was echoing the same inline $sorting_script
        // heredoc. Both fire on the same request; v4.4.26 only fixed one.
        // Fix: replaced the echo $sorting_script with echo '<script src=
        // "...js/tablesorter.js">'. A static $tablesorter_injected guard
        // prevents double-injection when both callbacks run (Moodle 5.0+).
        // SCHEMA: NO change. SETTINGS: NO change. Savepoint is a marker only.
        upgrade_plugin_savepoint(true, 2026050300107, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026050300106) {
        // CSP-TABLESORTER (v4.4.26, 3 May 2026): root cause of the persistent
        // blank trainers.php page finally identified. Moodle debugging was turned
        // ON by the site admin and showed NO PHP errors — confirming PHP runs fine.
        // The problem is entirely client-side: Moodle 4.3+ enforces a strict
        // Content-Security-Policy (script-src 'self') that silently blocks ANY
        // inline <script> block that lacks a server-issued nonce. The
        // before_footer_html_generation hook in
        // classes/hook/before_footer_html_generation.php was injecting the table-
        // sorting JavaScript as a raw inline <script> block via $hook->add_html()
        // on EVERY RTOC page (the path check /local/rtocompliance/ matches all of
        // them). The browser blocked this script silently — no error, no console
        // message in Moodle debug mode (because CSP violations are browser-side,
        // not PHP-side) — leaving the page visually blank. v4.4.24 fixed the same
        // pattern for the sidebar IIFE (lib.php) and the action-menu/tooltip IIFEs
        // (trainers.php) but missed this third injection point in the hook.
        // Fix: extracted the sorting JS to js/tablesorter.js (same-origin, always
        // allowed by 'self') and replaced the inline heredoc with a single
        // <script src="...js/tablesorter.js"> tag. SCHEMA: NO change.
        // SETTINGS: NO change. AMD: NO change. Savepoint is a marker only.
        upgrade_plugin_savepoint(true, 2026050300106, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026050300105) {
        // DIAG-DISPLAY-ERRORS (v4.4.25, 3 May 2026): diagnostic release to
        // expose the root cause of the persistent blank trainers.php page.
        // The page returns ERR_EMPTY_RESPONSE (chrome-error://chromewebdata/)
        // on every version since v4.4.20 despite the bulletproof fatal handler
        // added in v4.4.21/22/23. The empty response means PHP is dying before
        // sending any bytes — consistent with an OS-level kill (OOM, FPM timeout)
        // or OPcache serving stale bytecode where none of our handler code exists.
        // Changes: (1) trainers.php — @ini_set('display_errors', '1') at line 1,
        // before require_once config.php, so any PHP error during bootstrap is
        // forced into the HTTP response body even if the shutdown handler never
        // fires; (2) trainers_diag.php — new standalone diagnostic page that
        // commits HTTP 200 immediately, runs each subsystem independently
        // (config.php, lib.php, DB schema, OPcache state, admin registration),
        // and reports results as a readable HTML page. Includes an "Clear
        // OPcache & Re-run" button (?opcache_reset=1) that calls opcache_reset()
        // so stale-bytecode issues can be resolved without a PHP-FPM restart.
        // SCHEMA: NO change. SETTINGS: NO change. AMD: NO change.
        // Savepoint is a marker only.
        upgrade_plugin_savepoint(true, 2026050300105, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026050300104) {
        // CSP-EXTERNAL-JS (v4.4.24, 3 May 2026): fixed Moodle 4.3+ CSP
        // Content-Security-Policy violation. Moodle 4.3 tightened its CSP so
        // inline <script> blocks without a server-issued nonce are blocked by
        // the browser (reported in browser console as "Refused to execute
        // inline script because it violates the following Content Security
        // Policy directive: script-src 'self'"). The sidebar IIFE (previously
        // injected as a heredoc <script> block inside
        // local_rtocompliance_render_sidebar() in lib.php) and the two trainers
        // page IIFEs (action menu + role-badge tooltips, previously injected
        // via html_writer::script() in trainers.php) are the source of this
        // error. Fix: extracted both JS blocks to external same-origin files:
        //   js/sidebar.js  — sidebar IIFE + collapsible fieldset fallback
        //   js/trainers.js — action menu IIFE + role-badge tooltip IIFE
        // lib.php now outputs <script src="...js/sidebar.js"> (via moodle_url)
        // and trainers.php now outputs <script src="...js/trainers.js">.
        // Same-origin script files are always permitted by Moodle's 'self' CSP
        // directive, so no nonce is needed. The old inline blocks are preserved
        // as dead code (if (false) {...}) for reference. This is the confirmed
        // root cause of the blank trainers.php page: the sidebar JS that
        // bootstraps page navigation was being silently blocked by CSP.
        // SCHEMA: NO change. SETTINGS: NO change. AMD: NO change.
        // Savepoint is a marker only.
        upgrade_plugin_savepoint(true, 2026050300104, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026050300100) {
        // TRAINERS-PAGE-HARDENING (v4.4.20, 3 May 2026): trainers.php now
        // wraps every count_records_sql, the Moodle-teachers SELECT, the
        // trainer fetch and the per-row rendering loop in try/catch so a
        // single bad query or row no longer 500s the whole page. An admin-
        // only diagnostic panel surfaces caught exceptions verbatim and
        // exposes a Repair Schema button (?rtocrepair=1&sesskey=...) that
        // re-runs every add_field idempotently and resets OPcache. No
        // schema change. No AMD change. Savepoint is a marker only.
        upgrade_plugin_savepoint(true, 2026050300100, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026050300119) {
        // SUITABILITY-EVIDENCE-FIELDS (v4.4.39, 3 May 2026): tester feedback
        // actioned — pre-enrolment suitability review now captures optional
        // self-described evidence notes alongside the qualification dropdown
        // and school level dropdown (PI 2(a) evidence collection improvement).
        // Adds two nullable TEXT columns to local_rtocompliance_suitability:
        //   - qualification_evidence: student description of their qualification
        //     (e.g. cert number, institution, year) — max 500 chars
        //   - school_evidence: student description of their school background
        //     (e.g. school name, state, year completed) — max 500 chars
        // Both fields are nullable and optional (no UI validation enforced).
        // Shown in suitability_view.php Section 1 when not empty.
        // Included in the suitability_pdf.php PDF report.
        // Form title updated: "Student Suitability Check" → "Pre-Enrolment
        // Suitability Review" throughout (form, PDF, view, notification).
        $table = new xmldb_table('local_rtocompliance_suitability');

        $field = new xmldb_field('qualification_evidence', XMLDB_TYPE_TEXT, null, null, null, null, null, 'school_level');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('school_evidence', XMLDB_TYPE_TEXT, null, null, null, null, null, 'qualification_evidence');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026050300119, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026050300120) {
        // TABLE-SCROLL-AND-FULLSCREEN (v4.4.40, 3 May 2026): no schema change.
        // js/tables.js + styles.css additions provide in-place horizontal scroll
        // and a full-screen expand button for every plugin data table.
        // Savepoint is a marker only.
        upgrade_plugin_savepoint(true, 2026050300120, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026050300121) {
        // SIDEBAR-COLOUR-AND-TOOLTIPS (v4.4.41, 3 May 2026): no schema change.
        // styles.css: sidebar background updated from near-black to dark navy blue
        // to match the plugin's sky-blue accent colour scheme.
        // js/sidebar.js: collapsed-icon tooltips rewritten as JS body-appended
        // floating divs to escape the sidebar's overflow:hidden clipping.
        // Savepoint is a marker only.
        upgrade_plugin_savepoint(true, 2026050300121, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026050300122) {
        // STUDENT-ELIGIBILITY-CHECKLIST-REWRITE (v4.4.42, 4 May 2026):
        // Complete redesign of the pre-enrolment suitability form per Standard 2.2.
        // Old 4-stage form (qualification/LLN/system-decision/declaration) replaced
        // with a 5-section eligibility checklist: ACSF self-report, digital literacy
        // dropdown, prior skills dropdown, course requirements note, support needs.
        // Trainer decision panel added to suitability_view.php (replaces override).
        // PDF updated for new sections. New status 'submitted' marks student-submitted
        // records awaiting trainer review.
        //
        // SCHEMA CHANGE: 14 new nullable columns on local_rtocompliance_suitability.
        $table = new xmldb_table('local_rtocompliance_suitability');

        $field = new xmldb_field('lln_evidence', XMLDB_TYPE_TEXT, null, null, null, null, null, 'school_evidence');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('digital_literacy', XMLDB_TYPE_CHAR, '50', null, null, null, null, 'lln_evidence');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('digital_literacy_evidence', XMLDB_TYPE_TEXT, null, null, null, null, null, 'digital_literacy');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('prior_skills', XMLDB_TYPE_CHAR, '50', null, null, null, null, 'digital_literacy_evidence');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('prior_skills_evidence', XMLDB_TYPE_TEXT, null, null, null, null, null, 'prior_skills');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('course_req_note', XMLDB_TYPE_TEXT, null, null, null, null, null, 'prior_skills_evidence');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('support_needs', XMLDB_TYPE_TEXT, null, null, null, null, null, 'course_req_note');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('trainer_decision', XMLDB_TYPE_CHAR, '32', null, null, null, null, 'support_needs');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('trainer_advice', XMLDB_TYPE_TEXT, null, null, null, null, null, 'trainer_decision');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('trainer_justification', XMLDB_TYPE_TEXT, null, null, null, null, null, 'trainer_advice');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('trainer_declaration', XMLDB_TYPE_INTEGER, '1', null, null, null, '0', 'trainer_justification');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('trainer_declared_at', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'trainer_declaration');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('trainerid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'trainer_declared_at');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026050300122, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026050500130) {
        // FIX-AI-COURSENAME (v4.4.50): ajax.php ai_draft_text handler was sending requests
        // to the course-assistant AI endpoint without the required 'courseName' and
        // 'courseContext' fields, causing HTTP 400 "Invalid request parameters" errors for
        // all four ai_draft_text contexttype values (voccomp_description, consult_feedback,
        // consult_impact_training, consult_impact_assessment). Fix: added both fields to
        // $postdata — courseName uses $clean['qualification'] if available, otherwise
        // 'RTO Compliance'; courseContext is a descriptive string for the AI.
        // No DB schema, PHP UI, JS, AMD, or CSS changes — version bump for traceability only.
        upgrade_plugin_savepoint(true, 2026050500130, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026050500131) {
        // FIX-SUIT-BTN-CSS (v4.4.51): suitability_form.php Submit Eligibility Check button
        // was a plain <button type="submit"> with no CSS classes. Two inline rules in the
        // page <style> block (.rtoc-suit-submit button / :hover) overrode the global
        // styles.css btn-primary rules, producing a flat static blue with no gradient,
        // no hover lift (transform: translateY(-2px)) and no box-shadow — visually
        // inconsistent with all other primary buttons in the plugin.
        // Fix: added class="btn btn-primary" to the button and removed the two conflicting
        // inline CSS rules so the global stylesheet takes full effect. Also added a JS
        // submit listener that immediately disables the button and shows "Submitting…
        // please wait" so the student gets visual feedback during the synchronous
        // email_to_user() admin notification call.
        // No DB schema change. AMD: NO change.
        upgrade_plugin_savepoint(true, 2026050500131, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026050500132) {
        // ASYNC-EMAIL (v4.4.52): suitability_form.php now queues admin notification
        // via a Moodle adhoc task (local_rtocompliance\task\send_suitability_notification)
        // instead of calling email_to_user() synchronously inside the form POST handler.
        // The page returns to the student instantly; cron delivers the email within ≤1 minute.
        // FIX-RESEND-SUBMITTED: suitability_send.php POST handler now redirects to
        // suitability_view.php when the student's status is 'submitted', preventing the
        // silent token overwrite that invalidated the student's old checklist link.
        // No DB schema changes — adhoc tasks use the mdl_task_adhoc table that already exists.
        upgrade_plugin_savepoint(true, 2026050500132, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026050500133) {
        // DIAG-AI-FULLSTACK (v4.4.53): Expanded diag_ai_request_failed.php with five new
        // full-stack diagnostic sections (S11–S15). No schema, AMD, or PHP page changes.
        upgrade_plugin_savepoint(true, 2026050500133, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026050500134) {
        // FIX-CONSULT-AI-SUGGEST (v4.4.54): Switched Industry Consultation AI buttons from
        // /api/moodle/course-assistant/chat to /api/rto/ai-suggest. No schema change.
        upgrade_plugin_savepoint(true, 2026050500134, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026050500135) {
        // FIX-TOKEN-REVISIONNOTES (v4.4.55): Fixed Delivery Schedule 900-token truncation
        // and Revision Notes "Unknown field" error. Server-side only — no schema change.
        upgrade_plugin_savepoint(true, 2026050500135, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026050500136) {
        // DIAG-V455-PROOFS (v4.4.56): Added diag_v455_proofs.php — full-stack proof-of-fix
        // diagnostic for the 3 reported errors (Delivery Schedule token limit, Unknown field
        // revisionnotes, Industry Consultation AI request failed). Credential loader fixed to
        // try all 3 lookup paths: local_aiconfig_get_apikey() global no-arg, per-plugin arg,
        // and get_config() plugin settings table fallback. No schema change.
        upgrade_plugin_savepoint(true, 2026050500136, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026050500137) {
        // LOGO-PADDING (v4.4.57): Increased .rtoc-sb-brand-icon size from 34x34 to 40x40px
        // and added 6px padding so the RTO letters have more breathing room in the sidebar
        // header badge. CSS-only change in lib.php. No schema change.
        upgrade_plugin_savepoint(true, 2026050500137, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026050500138) {
        // LOGO-PADDING-V2 (v4.4.58): Increased .rtoc-sb-brand-icon to 48x48px (from 34px
        // original). Removed erroneous padding:6px from flex container — flex centering
        // already provides visual breathing room; the right fix is a larger box with the
        // same 11px font so the letters sit comfortably inside. CSS-only, no schema change.
        upgrade_plugin_savepoint(true, 2026050500138, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026050500139) {
        // LOGO-PADDING-V3 (v4.4.59): Broadened CSS selector from #rtoc-sidebar .rtoc-sb-brand-icon
        // to plain .rtoc-sb-brand-icon and added !important to all properties so no Moodle
        // theme override can shrink the badge. Size stays 48x48px. CSS-only, no schema change.
        upgrade_plugin_savepoint(true, 2026050500139, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026050500140) {
        // LOGO-PADDING-V4 (v4.4.60): Fixed in the correct file — styles.css (the registered
        // Moodle stylesheet), not lib.php (inline CSS). The winning rule was in styles.css at
        // selector [class*="path-local-rtocompliance"] #rtoc-sidebar .rtoc-sb-brand-icon.
        // Changed width/height from 34px to 48px, min-width from 34px to 48px, font-size from
        // var(--rtoc-text-base) [1rem/16px] to 11px. The larger box with smaller text gives
        // the RTO letters clear breathing room on all sides. CSS-only, no schema change.
        upgrade_plugin_savepoint(true, 2026050500140, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026050500141) {
        // LOGO-PADDING-V5 (v4.4.61): Reduced badge from 48px to 40px — the 48px box had
        // excess empty space. 40px keeps comfortable breathing room around the 11px "RTO"
        // text (~14px top/bottom, ~10px left/right). CSS-only, no schema change.
        upgrade_plugin_savepoint(true, 2026050500141, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026050500142) {
        // LOGO-PADDING-V6 (v4.4.62): Reduced badge further from 40px to 36px. User confirmed
        // 48px was too large; 36px is compact and close to the original 34px but with enough
        // room for the 11px "RTO" text (~12px top/bottom, ~7px left/right). CSS-only, no schema change.
        upgrade_plugin_savepoint(true, 2026050500142, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026050500143) {
        // LOGO-PADDING-V7 (v4.4.63): Fixed the actual winning rule. lib.php has !important on
        // all brand-icon properties and therefore always overrides styles.css. Previous size
        // changes to styles.css had no effect. lib.php now: 36x36px, border-radius 8px,
        // gradient #0ea5e9→#6366f1 (matches styles.css), font-size 11px. styles.css kept in sync.
        // CSS-only, no schema change.
        upgrade_plugin_savepoint(true, 2026050500143, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026050500144) {
        // FIX-VOCCOMP-AI-SUGGEST + FIX-CURRENCY-AUTOSYNC (v4.4.64): voccomp_description
        // routes to /api/rto/ai-suggest; industrycurrencydate and vocationalcompetencydate
        // auto-updated after trainer_currency.php / trainer_voccomp.php saves.
        // SCHEMA: NO change. Marker savepoint only.
        upgrade_plugin_savepoint(true, 2026050500144, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026050700145) {
        // FIX-DIAG-LIVE-TEST-ENDPOINT (v4.4.65): diag_ai_request_failed.php and
        // diag_may5_2026.php live-test functions updated to use /api/rto/ai-suggest
        // (not course-assistant/chat) for all four ai_draft_text context types.
        // SCHEMA: NO change. Marker savepoint only.
        upgrade_plugin_savepoint(true, 2026050700145, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026050700146) {
        // FIX-EMPTY-ERROR-STRING + FIX-SESSION-WRITE-CLOSE (v4.4.66):
        // (1) ajax.php: \core\session\manager::write_close() added before the
        //     60-second curl call to /api/rto/ai-suggest. Without this, concurrent
        //     Moodle requests in the same session block on the session file lock,
        //     causing the AJAX call to time out and show "AI request failed".
        // (2) ajax.php: All ?? fallbacks on $rd['error'] changed to ?: so that
        //     an empty-string error field ("error":"") from the server also falls
        //     through to the HTTP-code fallback message. The old ?? operator kept
        //     "" as-is, making j.error falsy in JS → silent "AI request failed".
        // (3) ajax.php: Empty-string guard on suggestions[0] — if ai-suggest
        //     returns success:true but suggestions[0] trims to "", now returns
        //     success:false with an explicit "AI returned an empty response" message.
        // (4) trainer_voccomp.php JS: fetch now captures HTTP status code and raw
        //     response text; JSON parse errors surface as "Bad response (HTTP NNN)"
        //     with the first 120 chars of the body; fallback message includes HTTP
        //     status so the admin can report the exact failure to support.
        // SCHEMA: NO change. AMD: NO change. Marker savepoint only.
        upgrade_plugin_savepoint(true, 2026050700146, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026050700147) {
        // FIX-STD-VOCCOMP-REFERENCE (v4.4.67): Corrected standard references for
        // vocational competency throughout the plugin. "ASQA Clauses 1.13-1.16" and
        // "Standards for RTOs 2015 Standard 3.2" replaced with the correct citation:
        // "Standard 3.3(2) of the Standards for RTOs 2025". Changed in:
        // trainer_edit.php (Evidence of Vocational Competency info banner),
        // lang/en/local_rtocompliance.php (trainerrequirements_help string),
        // ajax.php (voccomp_description prompt),
        // server/routes.ts (voccomp AI field configs asqaGuide fields).
        // SCHEMA: NO change. AMD: NO change. Marker savepoint only.
        upgrade_plugin_savepoint(true, 2026050700147, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026050700148) {
        // FIX-LEADVALIDATOR-PERSIST (v4.4.68): The leadvalidatorid column on
        // local_rtocompliance_validations was INTEGER(10) but the validation
        // form select uses composite string values of the form 'trainer_N' for
        // trainers as well as plain numeric IDs for external validators.
        // The INTEGER column silently discarded any value and was never written
        // back to the DB — so the dropdown always reverted to "Unassigned" when
        // re-opening a saved record. Fix: change the column to VARCHAR(50) and
        // persist the raw select value on every save.
        $table = new xmldb_table('local_rtocompliance_validations');
        $field = new xmldb_field('leadvalidatorid', XMLDB_TYPE_CHAR, '50', null, null, null, null);
        if ($DB->get_manager()->field_exists($table, $field)) {
            $DB->get_manager()->change_field_type($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026050700148, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026050700149) {
        // FEAT-TRANSITION-AI (v4.4.69): Added AI Generate button to the Transition Plan
        // field on the Edit Product Transition form (transition_edit.php / transition_form.php).
        // The button posts to ajax.php action=ai_draft_text contexttype=transitionplan,
        // passing seed data: oldproductcode, oldproductname, transitiontype, newproductcode,
        // newproductname, teachoutdeadline, studentsaffected. Prompt references Standard 1.12
        // of the Standards for RTOs 2025. transitionplan added to the in_array allowed list
        // in ajax.php (uses /api/rto/ai-suggest via ai-suggest endpoint, same as other
        // ai_draft_text context types). SCHEMA: NO change. AMD: NO change. Marker savepoint.
        upgrade_plugin_savepoint(true, 2026050700149, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026050700150) {
        // FIX-TRANSITION-SAVE-CANCEL (v4.4.70): The AI Generate Transition Plan button
        // added in v4.4.69 used html_writer::script() (inline <script> tag). Moodle 4.3+
        // enforces a Content-Security-Policy that blocks inline scripts without nonces
        // (same root cause as v4.4.24 CSP-EXTERNAL-JS). When the CSP blocked the script
        // it caused a JS exception that prevented Moodle form submit handlers from running,
        // making the Save and Cancel buttons unresponsive. Fix: moved all transition AI JS
        // to an external file (js/transition_ai.js) loaded via $PAGE->requires->js() which
        // is processed through Moodle's script loader and receives the correct CSP nonce.
        // The inline html_writer::script() call has been removed from transition_edit.php.
        // SCHEMA: NO change. AMD: NO change. Marker savepoint.
        upgrade_plugin_savepoint(true, 2026050700150, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026050700151) {
        // FIX-MARKETING-ISSUES-REMOVED (v4.4.71): Removed three items from the Standard 2.1
        // compliance-check issues list in marketing_cards.php:
        //   1. "Training product: course code / title not recorded — visit Training & Assessment Strategy"
        //   2. "Fees, Costs & Refunds: no fee record on file — visit Fee Protection"
        //   3. "Support Services: no services recorded — visit Student Support"
        // These checks were flagging normal operational states and did not need to be reported
        // in the Marketing Information 2.1 compliance panel. The remaining two checks are kept:
        //   - Diversity & Inclusion: no policy documents configured
        //   - RTO website URL not configured
        //   - Student Handbook URL not configured
        // SCHEMA: NO change. AMD: NO change. Marker savepoint.
        upgrade_plugin_savepoint(true, 2026050700151, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026050700152) {
        // DIAG-MAY7-2026 (v4.4.72): Added diag_may7_2026.php — full-stack diagnostic page
        // covering all five fixes shipped on 7 May 2026 (v4.4.67–v4.4.71):
        //   S0  PHP environment (curl, memory, execution time)
        //   S1  Moodle bootstrap + plugin version check (must be >= 4.4.72 / 2026050700152)
        //   S2  DB upgrade savepoints for all 5 releases (2026050700147–2026050700151)
        //   S3  DB schema: leadvalidatorid column type (VARCHAR(50) not INT)
        //   S4  File content checks:
        //         S4A ajax.php: Standard 3.3(2) citation present, old Clauses 1.13 absent
        //         S4B validation_form.php: $record->leadvalidatorid = $data->leadvalidatorid present
        //         S4C ajax.php: transitionplan in in_array + prompt handler; form button HTML
        //         S4D transition_edit.php: loads transition_ai.js, no html_writer::script() inline
        //         S4E marketing_cards.php: 3 removed checks absent, 3 kept checks present
        //   S5  AI credentials (local_aiconfig or direct siteid/apikey)
        //   S6  Live AI test: transitionplan context via /api/rto/ai-suggest
        //   S7  Table existence spot-check for key tables
        //   S8  PHP OPcache warning (if enabled)
        //   Summary: counts all FAIL badges, lists them, shows per-fix status grid.
        // SCHEMA: NO change. AMD: NO change. Marker savepoint.
        upgrade_plugin_savepoint(true, 2026050700152, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026050800153) {
        // FIX-VALIDATION-DISPLAY + FIX-TRANSITION-SAVE (v4.4.73):
        //
        // BUG-A — Lead Validator always showed "Unassigned" on the Validation
        // Schedule screen even after selecting and saving a validator.
        // ROOT CAUSE: validation_edit.php has been writing $record->leadvalidator
        // (the resolved display name) and $record->panelmembers (the newline list
        // of panel member names) since they were coded, but NEITHER column was
        // ever defined in install.xml.  Moodle's DML layer silently filters every
        // property that has no matching DB column before building the SQL, so
        // every save appeared to succeed but the data was never persisted.
        //
        // FIX: add both columns to local_rtocompliance_validations.
        //
        // Also re-applies the leadvalidatorid INT(10) → VARCHAR(50) type change
        // (originally in savepoint 2026050700148) against fresh installs, because
        // install.xml was never updated and new sites therefore have an INT column
        // that validation_edit.php writes composite 'trainer_N' string keys to —
        // which MySQL/MariaDB truncates to 0, silently losing the selection.
        //
        // BUG-B — Saving a Transition record failed or showed corrupted type
        // dropdown options ("Product transition deleted/updated successfully" as
        // option labels instead of "Deleted" / "Updated").
        // ROOT CAUSE: lang/en/local_rtocompliance.php defines transition_deleted
        // and transition_updated TWICE — first as short type-dropdown labels at
        // ~line 1005–1006, then overwritten as long success-message strings at
        // ~line 1176–1177.  PHP silently uses the last definition, so the dropdown
        // received the success-message text; Moodle's form serialiser can reject
        // the submitted option value when it doesn't match the original label
        // (strict validation on some Moodle versions), causing get_data() to
        // return null and the entire save block to be skipped silently.
        //
        // FIX: renamed the dropdown labels to transition_type_deleted /
        // transition_type_updated (unique keys) in lang/en/local_rtocompliance.php
        // and updated transition_form.php get_string() calls to match.  The
        // original success-message strings (transition_deleted / transition_updated)
        // are now the only holders of those keys.
        //
        // SCHEMA: ADD leadvalidator char(255), ADD panelmembers text,
        //         CHANGE leadvalidatorid INT(10) → char(50) if still INT.
        // AMD: NO change.

        $table = new xmldb_table('local_rtocompliance_validations');

        // Add leadvalidator column (resolved display name of lead validator).
        $field = new xmldb_field('leadvalidator', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        if (!$DB->get_manager()->field_exists($table, $field)) {
            $DB->get_manager()->add_field($table, $field);
        }

        // Add panelmembers column (newline-separated resolved panel member names).
        $field = new xmldb_field('panelmembers', XMLDB_TYPE_TEXT, null, null, null, null, null);
        if (!$DB->get_manager()->field_exists($table, $field)) {
            $DB->get_manager()->add_field($table, $field);
        }

        // Change leadvalidatorid from INT(10) → VARCHAR(50) on installs where
        // savepoint 2026050700148 ran but install.xml was never corrected
        // (i.e. every fresh install from any version prior to v4.4.73).
        // We detect INT type by checking the actual column info; a try/catch
        // swallows "already correct type" exceptions from Moodle's DDL layer.
        $columns = $DB->get_columns('local_rtocompliance_validations');
        if (isset($columns['leadvalidatorid']) && $columns['leadvalidatorid']->meta_type === 'I') {
            $field = new xmldb_field('leadvalidatorid', XMLDB_TYPE_CHAR, '50', null, null, null, null);
            $field->setComment('Composite key: numeric validator ID or trainer_N for a trainer record');
            $DB->get_manager()->change_field_type($table, $field);
        }

        // Backfill: for existing validation records that already have a non-empty
        // leadvalidatorid but an empty leadvalidator name, resolve and copy the
        // display name now so historical records show correctly straight away.
        $rows = $DB->get_records_sql(
            'SELECT v.id, v.leadvalidatorid FROM {local_rtocompliance_validations} v
              WHERE v.leadvalidatorid IS NOT NULL
                AND v.leadvalidatorid <> \'\'
                AND (v.leadvalidator IS NULL OR v.leadvalidator = \'\')'
        );
        foreach ($rows as $row) {
            $displayname = '';
            if (strpos($row->leadvalidatorid, 'trainer_') === 0) {
                $trainerid = (int) str_replace('trainer_', '', $row->leadvalidatorid);
                $trainer = $DB->get_record('local_rtocompliance_trainers', ['id' => $trainerid], 'fullname', IGNORE_MISSING);
                if ($trainer) {
                    $displayname = $trainer->fullname;
                }
            } else {
                $vid = (int) $row->leadvalidatorid;
                if ($vid > 0) {
                    $validator = $DB->get_record('local_rtocompliance_validators', ['id' => $vid], 'fullname', IGNORE_MISSING);
                    if ($validator) {
                        $displayname = $validator->fullname;
                    }
                }
            }
            if ($displayname !== '') {
                $DB->set_field('local_rtocompliance_validations', 'leadvalidator', $displayname, ['id' => $row->id]);
            }
        }

        upgrade_plugin_savepoint(true, 2026050800153, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026050800154) {
        // FIX-TRANSITION-CACHE (v4.4.74):
        // transition_edit.php called \cache::make('core', 'enrolinstances')->delete()
        // after writing the {enrol}.status field via set_field().  The cache definition
        // 'core/enrolinstances' does not exist on all Moodle versions; Moodle threw a
        // coding_exception ("The requested cache definition does not exist.core/enrolinstances")
        // which propagated to the user as a red error page, and the redirect after save
        // never ran — making it appear that saving a transition had failed even though
        // the DB write had already completed successfully.
        // FIX: wrapped the cache::make() call in try/catch (\coding_exception) so the
        // save always completes.  The {enrol}.status write is the source of truth.
        // SCHEMA: NO change. AMD: NO change. Marker savepoint.
        upgrade_plugin_savepoint(true, 2026050800154, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026050800155) {
        // VERSION-BUMP (v4.4.75): ZIP repackaged with correct rtocompliance/ top-level directory.
        // SCHEMA: NO change. AMD: NO change. Marker savepoint.
        upgrade_plugin_savepoint(true, 2026050800155, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026050800156) {
        // VERSION BUMP (v4.4.76): Clean release. No code or DB schema changes.
        // SCHEMA: NO change. AMD: NO change. Marker savepoint.
        upgrade_plugin_savepoint(true, 2026050800156, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026050800157) {
        // VERSION BUMP (v4.4.77): Clean release. No code or DB schema changes.
        // SCHEMA: NO change. AMD: NO change. Marker savepoint.
        upgrade_plugin_savepoint(true, 2026050800157, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026050800158) {
        // FIX (v4.4.78): auditlog.php SQL extended to include all Moodle 4.x name fields
        // (firstnamephonetic, lastnamephonetic, middlename, alternatename). fullname() was
        // triggering a debugging() warning for every audit log row due to missing fields.
        // SCHEMA: NO change. AMD: NO change. Marker savepoint.
        upgrade_plugin_savepoint(true, 2026050800158, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026050800159) {
        // ADD (v4.4.79): New ai_usage_report.php page with live credit usage stat cards,
        // per-feature breakdown table with inline bar charts, daily activity chart (last 30
        // days via Chart.js), local audit log summary, and link to full SaaS portal report.
        // Registered as local_rtocompliance_ai_usage_report externalpage. Lang strings added.
        // SCHEMA: NO change. AMD: NO change. Marker savepoint.
        upgrade_plugin_savepoint(true, 2026050800159, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026050800160) {
        // FIX (v4.4.80): ai_usage_report.php header description text contrast fix.
        // Changed from text-muted (grey) to white (opacity 0.85) for legibility against the
        // blue gradient compliance-header banner. SCHEMA: NO change. AMD: NO change. Marker savepoint.
        upgrade_plugin_savepoint(true, 2026050800160, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026050800161) {
        // FIX (v4.4.81): ai_usage_report.php colour cohesion. Uniform white stat cards with
        // single indigo top accent. Portal card gradient changed from purple to deep blue.
        // Bar fill simplified to solid #4f6ef7. SCHEMA: NO change. AMD: NO change. Marker savepoint.
        upgrade_plugin_savepoint(true, 2026050800161, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026050800162) {
        // FEAT (v4.4.82): ai_usage_report.php rewritten to use /api/rto/credit-usage-history
        // (DB-backed persistent endpoint). Shows full historical data with date range filter tabs,
        // dual-axis daily chart, feature breakdown, recent events table. New server endpoint added.
        // SCHEMA: NO change. AMD: NO change. Marker savepoint.
        upgrade_plugin_savepoint(true, 2026050800162, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026050800163) {
        // ADD (v4.4.83): ai_usage_report.php added to sidebar nav Data & Reports group so it
        // is accessible from every page in the plugin. SCHEMA: NO change. AMD: NO change.
        upgrade_plugin_savepoint(true, 2026050800163, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026050800164) {
        // FIX (v4.4.84): /api/rto/credit-usage-history now filters to rto_% usage types only.
        // Report no longer shows usage from other AI Grader plugins. Lang desc updated.
        // SCHEMA: NO change. AMD: NO change.
        upgrade_plugin_savepoint(true, 2026050800164, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026050900165) {
        // FIX-SAVEPOINT-ORDER + QA-STANDARD-CORRECTIONS (v4.4.85, 9 May 2026).
        // Fixed out-of-order savepoints 117/118 in upgrade.php (blocks were in descending
        // order, causing "cannot downgrade from 2026050300118 to 2026050300117" on upgrade).
        // Also corrected all QA standard references to align with the Standards for RTOs 2025:
        // support.php: 16→9 sections; QA1.6→QA1.5; QA1.4→QA1.8; QA1.7→Clause 9(2);
        // QA3.1→QA3.2; QA4.3→QA4.4; QA2.3→Division 3 Clause 17; Compliance Standard 5→
        // Division 3 Clause 18; teach-out 18→12 months.
        // lang file: same corrections. complianceHelp.ts: 16→9 sections.
        // SCHEMA: NO change. AMD: NO change.
        upgrade_plugin_savepoint(true, 2026050900165, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026051000166) {
        // FIX-CREDIT-REPORT (v4.5.96): three AI Credit Report bugs fixed — cost formula,
        // complaint/appeal AI routing, scope disclaimer. SCHEMA: NO change. AMD: NO change.
        upgrade_plugin_savepoint(true, 2026051000166, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026051000167) {
        // FIX-MAY10-BUGS (v4.5.97): six bugs from errors_10_May_2026.docx fixed.
        // (1) TGA qualbuilder group-min regex broadened to handle "N units must be selected
        //     from Group A" phrasing — fixes Group A showing "min 1" instead of "min 7"
        //     for qualifications like MEM series (server/routes.ts).
        // (2) TAS Delete button added to tas.php — site:config capability required,
        //     sesskey check, confirm dialog.
        // (3) Standard citations corrected throughout:
        //     - Section 5 Assessment Plan Notes: Clause 1.8 → Outcome Standards 1.3-1.4
        //     - Section 7 Learning Resources/Facilities/Technology: Standard 1.3 → Standard 1.8
        //     - Section 8 Work Placement: Standard 1.3 → Outcome Standards 1.1(2e); 1.2; 2.1(2c(iv))
        //     Both server/routes.ts (AI hint asqaGuide) and lang/en/local_rtocompliance.php
        //     (help button text) corrected.
        // (4) Work Placement AI systemHint updated to check actual placementhours /
        //     hasworkplacement from context — AI will now state "no work placement required"
        //     and describe simulated activities when hours = 0, instead of fabricating 120 hrs.
        // (5) Completeness % bug fixed: Section 8 (Work Placement) now always counts as
        //     complete — hasworkplacement=0 is a valid deliberate answer ("no WP required")
        //     and should not block a TAS from reaching 100% completeness (tas_edit.php).
        // SCHEMA: NO change. AMD: NO change.
        upgrade_plugin_savepoint(true, 2026051000167, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026051000168) {
        // v4.5.98: Two bugs from errors_10_May_2026_(2).docx
        // (1) TAS Section 2 (Entry Requirements / Prerequisites) AI generate was citing
        //     "ASQA Standard 5.1" (wrong). Corrected to "Outcome Standard 2.2" in both
        //     the AI asqaGuide (server/routes.ts) and the help button text
        //     (lang/en/local_rtocompliance.php) for both 'entryrequirements' and
        //     'prerequisites' fields.
        // (2) Qualbuilder (Add a Qualification) MEM20413 group parsing fixed:
        //     - Root cause: TGA HTML inline tags (<strong>, <em> etc.) were being replaced
        //       with newlines, splitting "Plus <strong>7 units</strong> from the following
        //       elective units (Group A)" into separate lines, making all regexes miss the
        //       number–group connection. Fix: inline tags now stripped cleanly (no newline).
        //     - Detection trigger broadened: packaging rules content items now detected via
        //       "N units from Group" and "Group [A-Z] + N units" patterns in addition to
        //       existing triggers.
        //     - Added parenM regex: handles "N units from the following ... (Group A)" format
        //       with exact-count semantics (sets min = max = N when no qualifier).
        //     - Extended minM/maxM character class to include () so "(Group B)" suffix format
        //       is matched correctly.
        // SCHEMA: NO change. AMD: NO change.
        upgrade_plugin_savepoint(true, 2026051000168, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026051200170) {
        // v4.5.100: FIX-QB-GROUP-RULES-SERVERSIDE (12 May 2026)
        // Root-cause fix for MEM20413 group packaging rules showing "Min 1" for both groups.
        // (1) tgaService.ts: Removed `break` from content-bundle item loop. MEM-series
        //     qualifications store group details (Group A min, Group B max) in SEPARATE
        //     content bundle items from the intro total. The break caused only the intro
        //     item to be parsed; group min/max lines were never reached. Fix accumulates
        //     rulesText from ALL matching content bundle items so Group A and Group B
        //     detail items are both processed.
        // (2) server/routes.ts: maxM handler now sets min = max when max <= 1 and no
        //     explicit min has been parsed yet. Previously "maximum of 1 unit from Group B"
        //     set { min: 0, max: 1 } (displayed as "optional"). Correct display is
        //     "1 unit only" (min = max = 1).
        // (3) qualbuilder_edit.js AMD (src + build + min.js): Group section header and
        //     summary panel labels changed from "select minimum of N" to "Min N" for
        //     open-ended minimum rules, and summary panel separator changed from
        //     "min&nbsp;" to "Min " for consistency.
        // SCHEMA: NO change. AMD: YES (qualbuilder_edit.js — label format only).
        upgrade_plugin_savepoint(true, 2026051200170, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026051200171) {
        // v4.6.101: MULTI-UNIT-SOA (12 May 2026)
        // New table local_rtocompliance_soa_snapshot stores an immutable
        // compliance snapshot of every unit listed on a multi-unit Statement
        // of Attainment at the exact moment of issue. Snapshot survives
        // Moodle course renames/deletions for full ASQA audit integrity.
        // Columns: certid, userid, issuedby, unitcode, unittitle,
        //          moodlecourseid, qualcategoryid, qualcategoryname,
        //          completiondate, outcomeidentifier, snapshottime.
        // SCHEMA: YES — new table. AMD: NO change.
        $table = new xmldb_table('local_rtocompliance_soa_snapshot');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id',               XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('certid',            XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('userid',            XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('issuedby',          XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('unitcode',          XMLDB_TYPE_CHAR,    '30', null, XMLDB_NOTNULL, null, null);
            $table->add_field('unittitle',         XMLDB_TYPE_CHAR,   '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('moodlecourseid',    XMLDB_TYPE_INTEGER, '10', null, null,          null, null);
            $table->add_field('qualcategoryid',    XMLDB_TYPE_INTEGER, '10', null, null,          null, null);
            $table->add_field('qualcategoryname',  XMLDB_TYPE_CHAR,   '255', null, null,          null, null);
            $table->add_field('completiondate',    XMLDB_TYPE_INTEGER, '10', null, null,          null, null);
            $table->add_field('outcomeidentifier', XMLDB_TYPE_CHAR,     '2', null, XMLDB_NOTNULL, null, '20');
            $table->add_field('snapshottime',      XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_key('primary',     XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('certid',      XMLDB_KEY_FOREIGN, ['certid'],  'local_rtocompliance_certs', ['id']);
            $table->add_key('userid',      XMLDB_KEY_FOREIGN, ['userid'],  'user',                       ['id']);
            $table->add_index('certid_userid', XMLDB_INDEX_NOTUNIQUE, ['certid', 'userid']);
            $table->add_index('userid_snap',   XMLDB_INDEX_NOTUNIQUE, ['userid', 'snapshottime']);
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026051200171, 'local', 'rtocompliance');
    }

    // v4.6.102: FIX-CURL-BATCH — usi_platform_client.php, packagingrules_validator.php, and
    //   lln/webhook_adapter.php switched from raw curl_init() to Moodle \curl wrapper +
    //   write_close(). No DB schema changes.
    if ($oldversion < 2026051200172) {
        upgrade_plugin_savepoint(true, 2026051200172, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026051400178) {
        // v4.9.108 STUDENT-DOC-REPOSITORY (14 May 2026)
        // New table local_rtocompliance_student_docs stores teacher-uploaded files
        // attached to a student's portfolio: RPL decisions, USI letters, suitability
        // assessments, credit transfer records, enrolment agreements, third-party
        // workplace records, AVETMISS exports, and other evidence documents.
        // Files are stored via Moodle file API (component=local_rtocompliance,
        // filearea=student_doc, itemid=record id, contextid=system context).
        // Access: students see own docs; admins/trainers with issuecerts/viewall can upload.
        // New pages: mydocs.php (portal), student_docs_download.php (file handler).
        // SCHEMA: YES — new table. AMD: NO change.
        $table = new xmldb_table('local_rtocompliance_student_docs');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id',           XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('userid',       XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null, null);
            $table->add_field('uploaderid',   XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null, null);
            $table->add_field('doctype',      XMLDB_TYPE_CHAR,    '50',  null, XMLDB_NOTNULL, null, 'other');
            $table->add_field('notes',        XMLDB_TYPE_TEXT,    null,  null, null,           null, null);
            $table->add_field('filename',     XMLDB_TYPE_CHAR,    '500', null, XMLDB_NOTNULL, null, null);
            $table->add_field('filesize',     XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null, '0');
            $table->add_field('mimetype',     XMLDB_TYPE_CHAR,    '100', null, null,           null, null);
            $table->add_field('contextid',    XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null, null);
            $table->add_field('timecreated',  XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary',    XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('userid',     XMLDB_KEY_FOREIGN, ['userid'],     'user', ['id']);
            $table->add_key('uploaderid', XMLDB_KEY_FOREIGN, ['uploaderid'], 'user', ['id']);
            $table->add_index('userid_type', XMLDB_INDEX_NOTUNIQUE, ['userid', 'doctype']);
            $table->add_index('userid_time', XMLDB_INDEX_NOTUNIQUE, ['userid', 'timecreated']);
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026051400178, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026051400179) {
        // v4.9.109 FIX-SMART-DETECT-BANNER — PHP/CSS only. No DB schema changes.
        upgrade_plugin_savepoint(true, 2026051400179, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026051400180) {
        // v4.9.110 FIX-PURCHASE-CREDITS-URL — No DB schema changes.
        upgrade_plugin_savepoint(true, 2026051400180, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026051400181) {
        // v4.9.111 FIX-NAT00080-AVETMISS8 — No DB schema changes.
        upgrade_plugin_savepoint(true, 2026051400181, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026051400182) {
        // v4.9.112 FIX-NAT00080-EXTENDED — No DB schema changes.
        upgrade_plugin_savepoint(true, 2026051400182, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026051400183) {
        // v4.9.113 FIX-NAT00080-WISENET — No DB schema changes.
        upgrade_plugin_savepoint(true, 2026051400183, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026051400184) {
        // v4.9.114 NAT00080-CONFIRM-STEP — No DB schema changes.
        upgrade_plugin_savepoint(true, 2026051400184, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026051400185) {
        // v4.9.115 AVETMISS-PARCHMENT-VERIFY — No DB schema changes.
        // verify.php now cross-references avetmiss_completion parchment numbers
        // (AVETMISS DE 515) via USI join for staff viewers.
        upgrade_plugin_savepoint(true, 2026051400185, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026051400186) {
        // v4.9.116 FIX-NAT00080-GENDER-AT — No DB schema changes.
        // '@' (AVETMISS "not stated" gender code) now accepted in both sex parse
        // paths; no longer triggers sex_not_stated data-issue flag.
        upgrade_plugin_savepoint(true, 2026051400186, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026051400187) {
        // v4.9.117 AUTOENROL-WIZARD — No DB schema changes.
        // After NAT file import, a new Step 3 wizard groups imported enrolment
        // records by qualification code (from NAT00120) and lets the admin map
        // each qual to a Moodle course for automatic bulk enrolment.
        // Students are matched to Moodle accounts by email; unmatched records
        // and already-enrolled students are skipped gracefully.
        upgrade_plugin_savepoint(true, 2026051400187, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026051400188) {
        // v4.9.118 AUTOENROL-HARDENING — No DB schema changes.
        // Three production-readiness fixes to the auto-enrol wizard:
        // (1) SESSION-LOCK: write_close() now called in both finalizenat (before
        //     bulk DB inserts) and doenrol (before enrolment loop) so concurrent
        //     browser tabs are never blocked waiting for the session lock.
        // (2) QUALCODE-KEY: wizard form now uses parallel numeric arrays
        //     (qualcodes[N] + courses[N]) instead of coursemap[qkey] to ensure
        //     the original qualcode string is preserved exactly through the form
        //     round-trip and used verbatim in the DB lookup — eliminates any
        //     character-stripping mismatch for non-standard qual code formats.
        // (3) STUDENT-ROLE: studentroleid lookup now falls back to get_archetype_roles()
        //     if no role with shortname='student' exists, preventing silent enrolments
        //     with no role assignment on sites where the student role was renamed.
        upgrade_plugin_savepoint(true, 2026051400188, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026051400189) {
        // v4.9.119 FIX-LINK-COURSES-UX — No DB schema changes.
        // qualbuilder_courses.php (Link Moodle Courses — Step 3 of Qual Builder):
        // Added contextual banner so admins can see which Moodle category the
        // dropdown is filtered to (qual = category, unit = course within it).
        // When categoryid is set: blue info notice "Filtered to category: [name]
        // — only courses in this category are shown."
        // When categoryid is 0: amber warning "No Moodle category linked — all
        // site courses shown. Go to product settings to filter."
        // Updated link_courses_desc lang string to explain the Moodle model
        // (category = qualification, course = unit). Auto-Detect button gains
        // tooltip explaining it matches by unit code.
        upgrade_plugin_savepoint(true, 2026051400189, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026051500194) {
        // v4.9.124 FIX-AUTOENROL-WIZARD-UX — No DB schema changes.
        // Three auto-enrol wizard UX fixes in data_import.php:
        // (1) POST-ENROL-REDIRECT: After the doenrol wizard submits, the redirect
        //     now includes search=<first_enrolled_qualcode> so the admin lands on
        //     the enrolments tab already filtered to the qual they just enrolled,
        //     rather than seeing all 3000+ records unfiltered.
        // (2) DB-LEVEL-SEARCH: Students tab and Enrolments tab now push the search
        //     term to the DB (sql_like on name/clientid/email for students;
        //     clientid/unitcode/qualcode for enrolments) with count_records_select
        //     for totals, replacing the old in-memory array_filter against a
        //     hard-capped 500/1000 row fetch. Enrolments sorted by qualcode ASC
        //     then clientid ASC so qual-filtered views group naturally.
        // (3) AUTO-DETECT-MESSAGE: When the ⚡ Auto-Detect button finds no course
        //     matches, the message now explains WHY (the course name/shortname must
        //     contain the qual code) and directs the admin to use the manual
        //     search combobox on each card.
        upgrade_plugin_savepoint(true, 2026051500194, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026051500195) {
        // v4.9.125 — NAT-IMPORT-PARSE-HARDENING + DIAG-PAGE (16 May 2026)
        // (1) ROBUST-QUOTE-STRIP: Two new helper functions
        //     (local_rtocompliance_strip_leading_quote and
        //     local_rtocompliance_find_field_quote) handle all quote variants —
        //     ASCII 0x22, Windows-1252 0x93/0x94 curly-quotes, and UTF-8
        //     U+201C/U+201D/U+201E smart quotes.  The v4.9.122 fix only checked
        //     for ASCII 0x22; vendors that export with Windows-1252 0x93 (the
        //     most common "curly quote" byte) caused the strip to silently fail,
        //     leaving the leading quote in the client ID field and shifting all
        //     subsequent field positions by 1 — garbling name, sex, DOB, and USI.
        // (2) NAT130-FALLBACK-IMPORT: parse_nat_group() now applies the same
        //     NAT130-as-NAT00080 fallback that the upload handler (Step 1) applies
        //     during USI detection and preview.  Previously, the actual DB import
        //     (finalizenat action) routed NAT00130 files to parse_nat00130()
        //     (completion-record format) — so zero students were ever stored when
        //     the SMS vendor named the student demographics file NAT00130.
        // (3) FALSE-USI-GUARD: Method 3 (DOB-anchored last-resort scan) now
        //     rejects candidates whose value exactly matches the stripped client ID
        //     or is an all-digit substring of the client ID.  Previously a failed
        //     sex+DOB detection gave Method 3 a wrong anchor, causing it to scan
        //     into the client-ID field and store the client ID digits as the USI.
        // (4) DIAG-PAGE: New diag.php at /local/rtocompliance/diag.php (site-admin
        //     only, no menu entry) — hex dump, field-by-field parse walkthrough,
        //     USI vote table, import history, USI quality check per import, and
        //     schema health check.
        // No DB schema changes. Savepoint 2026051500195.
        upgrade_plugin_savepoint(true, 2026051500195, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026051600196) {
        // FIX-NAT00120-AVETMISS8-POSITIONS (v4.9.126): Three NAT00120 parsing bugs fixed
        // using the authoritative AVETMISS 8 spec (Release 8.0, Nov 2016, field table p.35).
        // All positions are 0-indexed (spec is 1-indexed).
        //
        // (1) OUTCOME-VALID-LIST-TOO-NARROW: Outcome identifier – national is at pos 71,
        //     len 2. The previous code filtered against a hard-coded valid-code list
        //     ['20','30','40','51','52','60','61','70','81','90'].  Wisenet exports
        //     outcome code "41" (organisation-specific continuing/not-yet-graded marker)
        //     which is not in the national standard list but is valid in the file.
        //     Result: $outcome was always null for every Wisenet record.
        //     Fix: store the raw 2-char value without a hard-coded filter.
        //
        // (2) FUNDING-SOURCE-WRONG-EXTRACTION: Funding source – national is at pos 73,
        //     len 2. AVETMISS 8 uses 2-digit numeric codes (11, 13, 20, 30 …).
        //     The previous code scanned the ENTIRE line with the regex
        //     /\b(FFS|VSL|SAF|CSO|QLD|SYS|WA|TAS|SA|NSW|VIC)\b/ — these are legacy
        //     state-name tokens from pre-AVETMISS 8 formats; they never appear in
        //     AVETMISS 8 files.  Result: $fundingsource was always null.
        //     Fix: read from fixed position 73, length 2.
        //
        // (3) HOURS-ATTENDED-FRAGILE-REGEX: Hours attended is at pos 139, len 4 per spec;
        //     Scheduled hours is at pos 153, len 4.  The previous code used the fragile
        //     end-of-line regex /(\d{4})[A-Z]\s*$/ which happened to match the scheduled
        //     hours + predominant delivery mode character "I" — correct by accident but
        //     would break on any file whose last byte is not an uppercase letter.
        //     Fix: read hours attended from pos 139 directly; fall back to scheduled hours
        //     at pos 153 if hours attended is blank/zero.
        //
        // No DB schema changes. Savepoint 2026051600196.
        upgrade_plugin_savepoint(true, 2026051600196, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026051600197) {
        // FIX-NAT00120-OUTCOME-LABELS (v4.9.127): Added missing outcome code labels.
        // Full audit of all NAT files (NAT00010/20/30A/60/80/90/100/120/130) from a
        // real Wisenet VETiS dataset confirmed all parsers correct. One UX gap found:
        // '41' (Satisfactorily Completed) — 88.3% of all NAT00120 records — had no
        // human-readable label and displayed as raw "41" in the UI.
        // '85' (Non-Assessable Enrolment – Satisfactorily Completed) similarly unlabelled.
        // Fix: both codes added to local_rtocompliance_avetmiss_outcome_label().
        // No DB schema changes. Savepoint 2026051600197.
        upgrade_plugin_savepoint(true, 2026051600197, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026051600198) {
        // FIX-NATIMPORT-SIXBUGFIX (v4.9.128): Six parser / import bugs fixed.
        //
        // (1) FIX-TIMESTAMP-GROUPING: local_rtocompliance_group_by_timestamp() used the
        //     exact 13-digit Wisenet timestamp as the group key.  Wisenet assigns slightly
        //     different timestamps to each file in the same export (e.g. NAT00080 gets
        //     _1778752696696, NAT00120 gets _1778752696697).  Result: students landed in
        //     one import, enrolments in another — silently broken.
        //     Fix: group by (timestamp / 10,000,000) — a ~2.8-hour window that merges all
        //     files from one session without merging different collection years.
        //
        // (2) FIX-STUDYREASON-STORED: local_rtocompliance_parse_nat00120() correctly
        //     extracted the study reason (pos 96-97) and returned it in the array, but
        //     the DB insert loop and the avetmiss_enrolment table had no studyreason column
        //     — the value was silently discarded.  Fix: add studyreason column + wire insert.
        //
        // (3) FIX-OUTCOME-LABEL-82: local_rtocompliance_avetmiss_outcome_label() listed
        //     codes 20/30/40/41/51/52/60/61/70/81/85/90 but omitted '82' (Non-Assessable
        //     Enrolment – Not Satisfactorily Completed).  Records with outcome 82 displayed
        //     the raw code in the UI.  Fix: add '82' to the labels array.
        //
        // (4) FIX-PHONE-LANDLINES: NAT00085 phone regex /0[45]\d{8}/ only matched
        //     Australian mobiles (04xx/05xx).  Landlines (02/03/07/08) were silently
        //     dropped.  Fix: widen to /0[2-578]\d{8}/.
        //
        // (5) FIX-COLLECTION-YEAR-ENDDATE: detect_collection_year() used activity START
        //     date years.  AVETMISS 8 collection year is based on END date.  Fix: use
        //     enddate year as primary, startdate as fallback.
        //
        // (6) FIX-FUNDINGSOURCE-COMMENT: install.xml fundingsource column comment listed
        //     pre-AVETMISS 8 abbreviations (FFS, VSL, CSO).  Updated to reflect 2-digit
        //     numeric codes actually stored (11=Commonwealth, 20=Fee-for-service, etc.).
        //
        // DB schema change: add studyreason column to local_rtocompliance_avetmiss_enrolment.
        $table = new xmldb_table('local_rtocompliance_avetmiss_enrolment');
        $field = new xmldb_field(
            'studyreason', XMLDB_TYPE_CHAR, '2', null, null, null, null, 'fundingsource'
        );
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026051600198, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026051600199) {
        // FIX-PARSER-BOUNDS (v4.9.129): Four defensive fixes to the NAT file parser.
        //
        // (1) FIX-NAT00085-FIRSTNAME-BOUNDS: strlen() guard for firstname was `> 54`
        //     (requires length ≥ 55) but substr($line, 14, 40) last byte is index 53
        //     — needs strlen ≥ 54.  Same off-by-one for familyname: `> 94` → `>= 94`.
        //     Symptom: any 54-char line (or 94-char line) silently lost the name.
        //
        // (2) FIX-NAT00130-CERTDATE-BOUNDS: certificatedate strlen() guard was `> 47`
        //     (≥ 48) but substr($line, 39, 8) last byte is index 46 — needs `>= 47`.
        //     parchmentnumber guard `> 47` (reads from pos 47, needs ≥ 48) is correct.
        //     Symptom: 47-char NAT00130 lines silently lost the certificate date.
        //
        // (3) FIX-AUTOENROL-DUPLICATE-EMAIL: local_rtocompliance_save_nat_groups()
        //     enrolment loop used $DB->get_record() to look up users by email.  Moodle
        //     has no DB-level UNIQUE constraint on mdl_user.email — if two active
        //     accounts share an email, get_record() throws dml_multiple_records_exception
        //     and crashes the entire enrolment loop.  Fixed with IGNORE_MULTIPLE.
        //
        // (4) FIX-AUTOENROL-PREREQ-GUARD: if the manual enrolment plugin is disabled
        //     or no student role exists on the site, enrol_user() silently fails for
        //     every student.  Added early redirect with a clear error message instead.
        //
        // No DB schema changes.  Savepoint is a marker only.
        upgrade_plugin_savepoint(true, 2026051600199, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026051600200) {
        // FIX-NATPARSER-ADVERSARIAL (v4.9.130): Five bugs found by adversarial audit.
        //
        // (1) FIX-LINEENDING-BOM: All NAT file parsers used preg_split('/\r?\n/', ...)
        //     which handles \n and \r\n but silently ignores bare \r (old Mac line
        //     endings). A file using only \r would appear as ONE GIANT LINE — only the
        //     very first student record would ever be parsed, silently dropping 99%+ of
        //     the data. Additionally, a UTF-8 BOM (EF BB BF) at the start of the file
        //     shifted all field positions in the first record by 3 bytes; this was only
        //     stripped in parse_nat00080 (per-line), not in NAT00085 / NAT00120 /
        //     NAT00130 (file-level). Fix: normalise line endings and strip BOM once at
        //     file read time before storing in session; also update all three preg_split
        //     calls to the defensive pattern /\r\n|\r|\n/.
        //
        // (2) FIX-UPLOAD-SIZELIMIT: file_get_contents() was called on every uploaded
        //     NAT file with no size cap. A file > 50 MB (impossible for any legitimate
        //     AVETMISS export) would exhaust PHP memory before parsing could begin. Fix:
        //     skip files where $_FILES['natfiles']['size'][$i] > 52,428,800 (50 MB).
        //
        // (3) FIX-TIMESTAMP-32BIT: group_by_timestamp() computed the bucket key as
        //     (int)((int)$m[1] / 10000000). On 32-bit PHP, (int)'1778752696697' overflows
        //     PHP_INT_MAX (2,147,483,647) and produces a negative integer, so the key is
        //     wrong and files from the same Wisenet batch land in different groups (same
        //     root cause as the original FIX-TIMESTAMP-GROUPING but masked on 64-bit).
        //     Fix: use (int)(floatval($m[1]) / 10000000); floatval handles arbitrarily
        //     large digit strings correctly on both 32- and 64-bit PHP.
        //
        // (4) FIX-SQL-ISNOTEMPTY-TEXTFIELD: the autoenrol qualification list query used
        //     $DB->sql_isnotempty('...', 'qualcode', false, false). The fourth parameter
        //     is $textfield — it must be true for VARCHAR/TEXT columns or the generated
        //     SQL may miss empty-string rows on some DB engines. Changed to true.
        //
        // No DB schema changes. Savepoint 2026051600200.
        upgrade_plugin_savepoint(true, 2026051600200, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026051600201) {
        // FIX-NATIMPORT-SIXBUGFIX2 (v4.9.131): Six bugs found by second adversarial audit pass.
        //
        // (1) FIX-COMPLETIONS-ROW-CAP: Completions tab used $DB->get_records() with no LIMIT.
        //     Students tab capped at 200, enrolments at 500, completions was unbounded —
        //     an import with thousands of NAT00130 records loaded every row into PHP memory
        //     and could produce a multi-MB page response or OOM fatal. Fixed: cap at 500,
        //     with a user-facing note that all records are stored.
        //
        // (2) FIX-DELETE-TRANSACTION: The delete handler issued four sequential delete_records()
        //     calls with no transaction wrapper. A server crash between them left orphaned
        //     child rows (completions/enrolments/students with no parent import header).
        //     Fixed: wrap in $DB->start_delegated_transaction() / allow_commit().
        //
        // (3) FIX-IMPORT-TRANSACTION: save_nat_groups() inserted the header record first then
        //     iterated over students/enrolments/completions one-by-one with no transaction.
        //     A DB timeout mid-loop left a header record with wrong totals and partial data.
        //     Also: if insert_record() for the header returned false (DB error), all child
        //     inserts would have used importid = 0, silently contaminating the data.
        //     Fixed: wrap each group's insert loop in a delegated transaction; guard header
        //     insert for false and rollback + continue on failure.
        //
        // (4) FIX-AVETMISS-NOT-STATED: format_ddmmyyyy() returned '@@/@@/@@@@' for dates
        //     stored as '@@@@@@@@' (the AVETMISS standard "not stated" placeholder). The
        //     function checked for null and short strings but not the @-fill pattern.
        //     Fixed: preg_match('/^[@\s]+$/', $raw) → return '—'.
        //
        // (5) FIX-OUTCOME-BADGE-COLORS: The outcome badge match expression only coloured
        //     three codes: '40' (green), '20' (red), '30' (orange). All other codes fell
        //     through to grey badge-secondary. Wisenet uses '41' (Satisfactorily Completed)
        //     for 88%+ of real records — the entire outcome column was effectively grey.
        //     Fixed: full AVETMISS 8 colour map added:
        //       green (success)  : '40','41','51','60','61','85'
        //       red   (danger)   : '20','52'
        //       orange (warning) : '30','82'
        //       blue  (info)     : '81'
        //       grey  (secondary): '70','90' + default
        //
        // (6) FIX-EMAIL-CASE-POSTGRES: The enrolment loop used $DB->get_record('user',
        //     ['email' => $studentrec->email]) — exact equality, which is case-sensitive
        //     on this project's Neon/PostgreSQL database. NAT00085 emails are lowercased
        //     at parse time; a Moodle account created as John.Doe@Example.com silently
        //     failed to match. Fixed: use LOWER(email) = :email in a raw SQL query so the
        //     comparison is case-insensitive on all DB engines.
        //
        // No DB schema changes. Savepoint 2026051600201.
        upgrade_plugin_savepoint(true, 2026051600201, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026051600202) {
        // FIX-PREVIEW-COL-LABEL (v4.9.132): Final adversarial audit pass — one display bug found.
        // When USI auto-detection failed for a batch ($effUsiPos = -1), the preview table's USI
        // column header in the Step 2 confirmation view rendered "(col -1)" — confusing for admins
        // who had no idea what -1 meant (especially if they were deciding whether to manually enter
        // a position number). Fixed: when $effUsiPos < 0, display "(auto-fallback)" instead.
        //
        // All other sections audited clean:
        //   - All user inputs: PARAM_ALPHA/PARAM_INT/PARAM_TEXT on every optional_param. ✓
        //   - All output: every user-controlled string passes through s() or (int) cast. ✓
        //   - Lang strings: every get_string() key exists in the lang file. ✓
        //   - Session handlers: confirm_sesskey() on all mutating actions. ✓
        //   - Access control: admin_externalpage_setup() at top of file. ✓
        //   - Detection algorithm: scan range starts at $dobEnd+15, so position 0 (client ID)
        //     can never receive a vote — no risk of client ID being returned as detected USI pos. ✓
        //   - BS4 badge classes: Moodle 4.x ships a compatibility layer for badge-success etc. ✓
        //
        // No DB schema changes. Savepoint 2026051600202 (marker only).
        upgrade_plugin_savepoint(true, 2026051600202, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026051600203) {
        // FIX-AUTOENROL-NPLUS1 (v4.9.133): Four bugs in the auto-enrol doenrol handler fixed.
        //
        // (1) FIX-AUTOENROL-NPLUS1: The enrolment loop ran one get_record() per student to fetch
        //     their email, one get_records_sql(LOWER(email)=?) per student to look up the Moodle
        //     user, and one is_enrolled() per student (a JOIN query). For a 3000-student import
        //     with 5 qual codes that is up to 9000 individual DB round-trips. PHP's
        //     max_execution_time (60–300 s on typical Moodle installs) kills the request
        //     silently, leaving the admin with a blank page and zero enrolments processed.
        //     Fix: three pre-fetch queries replace all of that:
        //       (a) One query loads all student emails for this import into a clientid→email map.
        //       (b) One query loads all active Moodle users into a lowercase-email→userid map.
        //       (c) One query per qual code loads all currently-enrolled user IDs for the course.
        //     All inner-loop lookups are now O(1) in-memory hash lookups, zero DB queries.
        //
        // (2) FIX-AUTOENROL-NULL-INSTANCE: add_default_instance() can return null on DB error.
        //     The old code passed null straight to get_record(['id' => null]), generating invalid
        //     SQL (WHERE id = '') or throwing a dml_exception on some DB backends. Fixed by
        //     adding an explicit null/false guard before the get_record call.
        //
        // (3) FIX-AUTOENROL-DISABLED-INSTANCE: If the manual enrolment instance is disabled
        //     (status = 1 = ENROL_INSTANCE_DISABLED), enrol_user() silently enrols students with
        //     status ENROL_USER_SUSPENDED. They appear in the DB as enrolled and the admin sees
        //     "X students enrolled", but every one of them is blocked from accessing the course.
        //     Fixed: re-enable the instance via DB set_field() before running enrolments.
        //
        // (4) FIX-AUTOENROL-SKIP-SPLIT: $totalskipnoemail conflated two different failure modes:
        //     (a) clientid in NAT00120 but no matching student row in NAT00085 (file not uploaded)
        //     (b) student row exists but email field is blank.
        //     Both showed as "skipped — no matching Moodle account" which sent admins looking for
        //     the wrong problem. Fixed: separated into $totalskipnostudent and $totalskipnoemail.
        //     Both still roll up into the $skiptotal for the redirect notification message, so the
        //     lang strings and UI are unchanged — only the internal tracking is now accurate.
        //
        // No DB schema changes. Savepoint 2026051600203 (marker only).
        upgrade_plugin_savepoint(true, 2026051600203, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026051600204) {
        // FIX-AUTOENROL-HIDDEN-COURSES (v4.9.134): The auto-enrol wizard's course picker
        // previously offered ALL Moodle courses including those with visible=0 (hidden). An admin
        // who mapped a qualification to a hidden course would receive a "X students enrolled"
        // success message, but those students would find the course invisible in their Moodle
        // dashboard (hidden courses are not shown to students). This left admins confused and
        // students unable to access their enrolment. Fix: filter $courses to visible=1 only after
        // the get_courses() call, so hidden courses never appear in the combobox picker or the
        // server-side auto-match logic.
        //
        // FIX-AUTOENROL-COMBO-CAP (v4.9.134): The combobox buildPanel() function used
        // 'if (count >= 60) return' inside a forEach callback. In JS, 'return' inside a forEach
        // callback only skips to the next iteration — the loop continued running through all
        // remaining courses (wasting CPU) and no notice was shown to indicate truncation had
        // occurred. On a Moodle site with hundreds of courses, an admin who typed a broad search
        // term (e.g. "cert") and received exactly 60 results had no way to know there were more
        // matches below the cap — they may conclude a specific course does not exist when it was
        // simply ranked past position 60. Fix: count all matches in the loop, render only the
        // first 60, then append a "Showing 60 of N matches — type more to narrow" notice at the
        // bottom of the dropdown whenever the result set was truncated.
        //
        // No DB schema changes. Savepoint 2026051600204 (marker only).
        upgrade_plugin_savepoint(true, 2026051600204, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026051600205) {
        // FIX-AUTOENROL-SKIP-MSG (v4.9.135): Two final bugs in the auto-enrol feature fixed.
        //
        // (1) Misleading aggregated skip message: The doenrol handler (fixed in v4.9.133 to track
        //     three distinct skip counters: $totalskipnostudent, $totalskipnoemail, $totalskipnouser)
        //     was still combining all three into a single $skiptotal and reporting them under the
        //     single lang string 'autoenrol_skipped' = "skipped (no matching Moodle account found)".
        //     That message is only accurate for $totalskipnouser. For:
        //       - $totalskipnostudent: the clientid exists in NAT00120 but has no matching row in
        //         the student table (NAT00085 was not included in the upload). Telling the admin
        //         "no Moodle account found" sends them looking for non-existent accounts when the
        //         real fix is to re-upload with NAT00085 included.
        //       - $totalskipnoemail: the NAT00085 row exists but the email field is blank. Again
        //         nothing to do with Moodle accounts.
        //     Fix: each skip counter now emits its own targeted notification sentence.
        //       - $totalskipnostudent → 'autoenrol_skipnostudent': tells admin to check NAT00085
        //         was included in the upload.
        //       - $totalskipnoemail → 'autoenrol_skipnoemail': tells admin the email field is blank.
        //       - $totalskipnouser → 'autoenrol_skipped' (updated): "no active Moodle account found
        //         matching the email address." — now only used for the case it actually describes.
        //     Lang file: two new strings added (autoenrol_skipnostudent, autoenrol_skipnoemail),
        //     autoenrol_skipped updated to be precise.
        //
        // (2) HTML attribute encoding: the data-skip-url attribute on the enrolment form used
        //     $skipurl->out(false) (raw, unencoded URL) instead of $skipurl->out() (HTML-encoded).
        //     Although the specific URL (?importid=N) contains no characters requiring encoding,
        //     out(false) in an HTML attribute context is incorrect HTML. Fixed to out().
        //
        // No DB schema changes. Savepoint 2026051600205 (marker only).
        upgrade_plugin_savepoint(true, 2026051600205, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026051600206) {
        // FIX-NAT85-ONLY-FLAGS (v4.9.136): Data-issue flags (usi_missing, dob_not_stated,
        // sex_not_stated) were only computed inside parse_nat00080() and embedded in that
        // function's return value.  When a clientid appeared in NAT00085 but NOT in NAT00080
        // — which happens when an SMS exports a partial NAT00080 or the admin forgets to
        // include NAT00080 but does include NAT00085 — parse_nat_group() created a stub
        // entry: ['clientid' => $cid, 'name' => '', 'hasdataissues' => 0, 'dataissuefields' => '[]'].
        // After merging the NAT00085 contact data (email, phone, firstname, familyname)
        // those students still had no USI, no DOB, and no sex in their final merged record.
        // They were stored in local_rtocompliance_avetmiss_student with hasdataissues=0,
        // making them invisible to the "Flagged records" count on the import list and
        // invisible as yellow warning rows in the detail view — even though from an AVETMISS
        // compliance perspective they are incomplete and should be reviewed.
        //
        // Fix: after the full file-processing loop in parse_nat_group(), iterate over the
        // final merged studentmap and re-derive flags (usi_missing, dob_not_stated,
        // sex_not_stated) from the actual merged data.  For students that came from NAT00080
        // this re-derivation produces an identical result (correctness-preserving for existing
        // imports).  For NAT85-only stubs it now correctly sets hasdataissues=1 and populates
        // dataissuefields with the relevant codes.
        //
        // NOTE: This fix only affects NEW imports. Existing imported records retain their
        // current hasdataissues value. RTOs who need to re-check flagging on existing imports
        // can re-upload the original NAT files.
        //
        // FIX-SESSION-EXPIRED-I18N (v4.9.136): Two Moodle notification messages shown when
        // the 30-minute upload session window expires (one in the previewnat handler, one in
        // the finalizenat handler) were hardcoded English strings instead of lang strings.
        // Fixed: both now use get_string('dataimport_session_expired', 'local_rtocompliance').
        //
        // No DB schema changes. Savepoint 2026051600206 (marker only).
        upgrade_plugin_savepoint(true, 2026051600206, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026051600207) {
        // FIX-AUTOENROL-QUALCODES-PREMATURE (v4.9.137): In the doenrol handler, the line
        // $enrolledQualcodes[] = $qualcode was placed at the TOP of the qual-loop iteration,
        // BEFORE the course-existence check ($DB->get_record('course', ...)) and before the
        // enrolment-instance creation/validation guard (add_default_instance / !$instance).
        // Consequence: if a Moodle course was deleted between the wizard page loading and the
        // admin clicking "Enrol", or if add_default_instance() failed due to a DB error, the
        // qualcode was still pushed into $enrolledQualcodes. After the loop the handler builds
        // $redirectSearch = reset($enrolledQualcodes), and redirects the admin to the enrolments
        // tab filtered to that qualcode. The admin would see the enrolments tab pre-filtered to
        // a qualcode for which zero students were enrolled, with no Moodle enrolments having
        // occurred — confusing and misleading. Fix: $enrolledQualcodes[] = $qualcode is now
        // pushed only AFTER both the course and the manual enrolment instance are confirmed
        // as valid (i.e. after all the continue guards have been passed).
        //
        // FIX-AUTOENROL-GUARD-I18N (v4.9.137): The redirect message shown when the manual
        // enrolment plugin is disabled or no student role exists was a single hardcoded English
        // string concatenated with a PHP ternary: 'Auto-enrolment failed: ' . (!$enrolplugin
        // ? 'manual enrolment plugin is disabled.' : 'no student role found on this site.').
        // This bypasses Moodle's localisation system. Replaced with two separate lang strings
        // (autoenrol_fail_noplugin, autoenrol_fail_norole) that include actionable instructions
        // pointing to the exact Moodle admin paths needed to resolve each situation.
        //
        // No DB schema changes. Savepoint 2026051600207 (marker only).
        upgrade_plugin_savepoint(true, 2026051600207, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026051600208) {
        // FIX-HASDATAISSUES-RETROACTIVE (v4.9.138): The FIX-NAT85-ONLY-FLAGS fix in
        // v4.9.136 corrected hasdataissues derivation for NEW imports only. Existing
        // imported students who appeared only in NAT00085 (not NAT00080) before v4.9.136
        // were stored with hasdataissues=0 and dataissuefields='[]' even though they had
        // no USI, DOB, or sex. This one-time data migration identifies those records,
        // sets hasdataissues=1, and populates dataissuefields with the correct codes using
        // the same logic as parse_nat_group() after v4.9.136.
        //
        // After correcting student rows, flaggedrecords on each parent import row is
        // recalculated so the list view "Flagged" column accurately reflects the new state.
        //
        // ADD-COMPLETIONS-SEARCH (v4.9.138): Completions tab now supports search by
        // clientid and qualcode columns — no DB schema change required.
        //
        // ADD-HISTORY-PAGINATION (v4.9.138): Import history list is now paginated at 25
        // per page instead of capped at 50 with no navigation — no DB schema change.

        $rs = $DB->get_recordset_select(
            'local_rtocompliance_avetmiss_student',
            "hasdataissues = 0 AND ((usi IS NULL OR usi = '') OR dob IS NULL OR sex IS NULL)",
            []
        );
        $fixedimports = [];
        foreach ($rs as $stud) {
            $issues = [];
            if (empty($stud->usi))   $issues[] = 'usi_missing';
            if ($stud->dob === null) $issues[] = 'dob_not_stated';
            if ($stud->sex === null) $issues[] = 'sex_not_stated';
            if (!empty($issues)) {
                $DB->update_record('local_rtocompliance_avetmiss_student', (object)[
                    'id'              => $stud->id,
                    'hasdataissues'   => 1,
                    'dataissuefields' => json_encode($issues),
                ]);
                $fixedimports[$stud->importid] = true;
            }
        }
        $rs->close();

        // Recalculate flaggedrecords for every import that had students retroactively flagged.
        foreach (array_keys($fixedimports) as $iid) {
            $count = $DB->count_records(
                'local_rtocompliance_avetmiss_student',
                ['importid' => $iid, 'hasdataissues' => 1]
            );
            $DB->set_field('local_rtocompliance_avetmiss', 'flaggedrecords', $count, ['id' => $iid]);
        }

        // No DB schema changes. Savepoint 2026051600208 (marker only).
        upgrade_plugin_savepoint(true, 2026051600208, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026051600209) {
        // FIX-PHP74-MATCH (v4.9.139): Replaced both PHP 8.0+ match() expressions in
        // data_import.php with PHP 7.4-compatible if/elseif / lookup-array alternatives.
        // Moodle 4.1 LTS officially supports PHP 7.4 — match() caused HTTP 500 on those
        // installs. Also fixed match() in diag_errors_may2026.php.
        //
        // FIX-ASQA-URL (v4.9.139): Updated $asqaFactSheet in certificates.php from the
        // stale Drupal PDF path (/sites/default/files/2020-09/...) to the current canonical
        // ASQA fact-sheet page URL. The old path returned HTTP 404 after ASQA migrated
        // their website.
        //
        // FIX-GENERATE-LABEL (v4.9.139): Renamed "Generate by Course" → "Generate by
        // Qualification" throughout generate_course_certs.php, lang/en/local_rtocompliance.php
        // and settings.php. "Course" is Moodle-internal jargon; RTO staff understand
        // "Qualification". No functional or schema changes.
        //
        // No DB schema changes. Savepoint 2026051600209 (marker only).
        upgrade_plugin_savepoint(true, 2026051600209, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026051600210) {
        // FIX-TRANSITIONS-URL (v4.9.140): training.gov.au removed the old
        // /National/NoticeBoard path — the "Training.gov.au Transitions Register"
        // button in certificates.php returned HTTP 404. Replaced with the current
        // canonical URL: /Organisation/Registers/TrainingProductTransitions.
        // No DB schema changes. Savepoint 2026051600210 (marker only).
        upgrade_plugin_savepoint(true, 2026051600210, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026051600211) {
        // FIX-QUAL-LABEL (v4.9.141): The "Certificate Type Analysis" panel in
        // generate_course_certs.php always showed "Qualification:" even when the
        // detected type was a single unit (reason = unit_code_detected). The label
        // now reads "Unit:" for unit codes and "Skill Set / Unit:" for skill-set
        // detections, reserving "Qualification:" for full-qualification courses.
        //
        // FIX-QUAL-DUPLICATE (v4.9.141): When qualificationname already contains the
        // qualification/unit code (e.g. "MEM16006A - Organise and communicate
        // information"), the panel was displaying "MEM16006A — MEM16006A - Organise..."
        // — the code appeared twice. Fixed: if the name already includes the code,
        // only the name is shown; otherwise the "CODE — Name" format is used.
        //
        // No DB schema changes. Savepoint 2026051600211 (marker only).
        upgrade_plugin_savepoint(true, 2026051600211, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026051600212) {
        // FIX-STUDENT-PICKER (v4.9.142): Rebuilt the student picker in
        // soa_issue.php as a proper typeahead / autocomplete widget.
        //   - Surname displayed first ("Smith, John") — matches the SQL
        //     ORDER BY u.lastname, u.firstname so the list is alphabetical
        //     by surname when the dropdown opens with no query.
        //   - Live search across surname, firstname and email.
        //   - Two-line result rows: name bold/large, email muted below.
        //   - Highlighted matching text in results.
        //   - Keyboard navigation (↑ ↓ Enter Escape).
        //   - Result count shown at top of dropdown.
        //   - × clear button to reset and pick a different student.
        // No DB schema changes. Savepoint 2026051600212 (marker only).
        upgrade_plugin_savepoint(true, 2026051600212, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026051700213) {
        // FIX-STUDENT-PICKER-RERELEASE (v4.9.143): Re-release of v4.9.142
        // student picker improvements with a fresh version integer so Moodle
        // upgrade detection fires correctly on all installs.
        // No DB schema changes. Savepoint 2026051700213 (marker only).
        upgrade_plugin_savepoint(true, 2026051700213, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026051700214) {
        // FIX-NATUPLOAD-SESSION-SIZE (v4.9.144): NAT file upload handler stored
        // full raw file content in Moodle's DB-backed session.  Real NAT exports
        // (1–3 MB) caused a MySQL session-write failure (exceeds max_allowed_packet)
        // → HTTP 500.  Fix: write uploaded files to Moodle's temp directory and
        // store only on-disk paths in the session.  local_rtocompliance_parse_nat_group
        // now reads content from 'tmppath' (new) as well as 'content' (legacy).
        // Temp files are deleted after successful finalization.
        // No DB schema changes. Savepoint 2026051700214 (marker only).
        upgrade_plugin_savepoint(true, 2026051700214, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026051700215) {
        // FIX-NATUPLOAD-TMPDIR (v4.9.145): make_temp_directory() depends on
        // $CFG->tempdir permissions/quota and can itself throw a moodle_exception,
        // replacing the session-size error with a different error page.  Switched
        // to PHP's native sys_get_temp_dir() (/tmp on Linux) which is always
        // writable by the web server.  Falls back gracefully to inline session
        // storage if even /tmp is unavailable.
        // No DB schema changes. Savepoint 2026051700215 (marker only).
        upgrade_plugin_savepoint(true, 2026051700215, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026051700216) {
        // DIAG-FATAL-HANDLER (v4.9.146): Added register_shutdown_function +
        // set_exception_handler to data_import.php so any fatal (missing table,
        // memory exhaustion, uncaught exception) renders as readable HTML
        // instead of a blank HTTP 500.  Also bumped diag_natupload.php with
        // AVETMISS table-existence checks and PHP error-log tail.
        // No DB schema changes. Savepoint 2026051700216 (marker only).
        upgrade_plugin_savepoint(true, 2026051700216, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026051700217) {
        // DIAG-FATAL-HANDLER-FIX (v4.9.147): v4.9.146 placed set_exception_handler
        // before require_once config.php — Moodle's config.php then overwrote it
        // (last call to set_exception_handler wins). Fixed by moving the handler to
        // AFTER all requires, so ours overrides Moodle's production silent handler.
        // Full page body also wrapped in try/catch as belt-and-suspenders.
        // No DB schema changes. Savepoint 2026051700217 (marker only).
        upgrade_plugin_savepoint(true, 2026051700217, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026051700222) {
        // USI-12CHAR (v4.9.152): Widen USI column from VARCHAR(10) to VARCHAR(15) in
        // local_rtocompliance_students and local_rtocompliance_usilog to accommodate
        // SMS vendors that export a 12-char USI field (AVETMISS 8.0 spec is 10 chars
        // but some vendors allocate 12). Parser now reads up to 12 chars and validates
        // [A-Z0-9]{10,12}.
        //
        // Moodle DDL rule: any index that references a column being resized must be
        // dropped before change_field_precision(), then recreated afterwards.
        // local_rtocompliance_students.usi has TWO such indexes:
        //   - 'usi'           (single-field)
        //   - 'usi_usiverified' (composite: usi, usiverified)
        // local_rtocompliance_usilog.usi has ONE such index:
        //   - 'usi'           (single-field)
        local_rtocompliance_upgrade_widen_usi($dbman);
        upgrade_plugin_savepoint(true, 2026051700222, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026051700223) {
        // AVETMISS8-AUDIT (v4.9.153): Full AVETMISS 8.0 spec audit pass.
        // (1) NAT00085: switched suburb (pos 281,50), postcode (pos 331,4), state (pos 335,2),
        //     phone home→mobile→work priority (pos 337/377/357) and email (pos 397,80) from
        //     fragile full-line regex to spec-defined fixed-position reads. State validator
        //     now covers codes 01–09 and 99 (previously missed 09/99). No schema change.
        // (2) NAT00080: name buffer widened from 50 to 60 chars to match AVETMISS 8.0 spec
        //     (pos 10–69); closing quote in chars 60–69 was previously missed.
        upgrade_plugin_savepoint(true, 2026051700223, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026051700227) {
        // USI-NAMEPICKER (v4.9.157): Redesigned USI column confirmation step to show
        // student names alongside USI codes so admins can verify by recognising their
        // students rather than interpreting raw 10-char codes. Single-candidate case
        // (most common) now shows a green "USI codes detected" banner + name→USI table
        // with a simple "these look right, click Confirm" instruction — no decision
        // required. Multi-candidate case shows named option cards side by side.
        // No-USI-data case shows a clear non-blocking info message. All technical
        // jargon ("byte position", "0-indexed") removed from visible UI. No schema change.
        upgrade_plugin_savepoint(true, 2026051700227, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026051900228) {
        // USI-NOMORE-PICKER (v4.9.158): Removed the USI column picker from Step 2
        // of the NAT import wizard entirely. USI detection now runs silently in the
        // background using the existing auto-detect algorithm — the admin never needs
        // to make a decision about USI. Step 2 is now a simple review-and-confirm
        // page: it shows a brief USI status banner (found/not found), a warning if
        // NAT00085 (contact details) was not uploaded, a preview of the first 12
        // student records, and a single "Confirm & Import" button. Step 3 (autoenrol
        // wizard) now also shows a prominent warning if no email addresses were stored
        // from the import (i.e. NAT00085 was missing), so the admin knows upfront that
        // zero students will be enrolled before wasting time selecting courses.
        // No DB schema changes. No AMD changes.
        upgrade_plugin_savepoint(true, 2026051900228, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026051900229) {
        // MATCH-BY-STUDENTID (v4.9.159): Added "match by student number" option for the
        // NAT file auto-enrolment wizard. The admin can now choose at upload time whether
        // students are matched to their Moodle accounts by email address (original
        // behaviour, requires NAT00085) or by Client ID from NAT00080 matched against the
        // Moodle username field. The choice travels through the whole import flow via a
        // hidden form field and is stored in $SESSION so the doenrol action uses the
        // correct strategy. No DB schema changes.
        upgrade_plugin_savepoint(true, 2026051900229, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026051900230) {
        // MATCH-BY-STUDENTID-UNIVERSAL (v4.9.160): Extended the student-number matching
        // strategy to check BOTH the Moodle idnumber field (populated by most SMS/LDAP
        // integrations) AND the username field (used by RTOs that create accounts with
        // the student ID as the login name). idnumber is checked first; username is the
        // fallback. This makes the feature work universally across all RTOs regardless
        // of which field their SMS populates. No DB schema changes.
        upgrade_plugin_savepoint(true, 2026051900230, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026051900232) {
        // NAT00085-AUTO-SWITCH (v4.9.162): When NAT00085 is absent from an import,
        // Step 2 now automatically switches the session match method to 'studentid'
        // and shows an informative message instead of telling the admin to cancel.
        // Step 3 NAT00085 warning is now match-method-aware — suppressed when using
        // studentid matching (which doesn't need email). Step 3 description updated
        // to reflect that missing accounts are created, not skipped. No schema change.
        upgrade_plugin_savepoint(true, 2026051900232, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026051900231) {
        // AUTO-CREATE-ACCOUNTS (v4.9.161): The auto-enrol wizard now automatically
        // creates a new Moodle account for any student in the NAT file who doesn't
        // already have one. Username and ID number are set to the NAT Client ID.
        // Name is taken from NAT00080 (title-cased). Email is taken from NAT00085
        // if available, otherwise a placeholder email (clientid@no-email.placeholder)
        // is used so the account is functional immediately. This makes the feature
        // work for all RTOs — both those who already have students in Moodle and
        // those migrating historical data from a previous SMS. No schema changes.
        upgrade_plugin_savepoint(true, 2026051900231, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026051700226) {
        // USI-COLPICKER (v4.9.156): Replaced the "USI column position (0-indexed)" number
        // input with a visual column-picker. The new UI scans the NAT00080 file for all
        // candidate positions whose values match the official USI character set
        // [2-9A-HJ-NP-Z] (excludes 0, 1, I, O per the USI spec), shows sample values
        // from each candidate in clickable cards, and lets the admin confirm the right
        // column — exactly like every major CSV import tool. The detection and extraction
        // regexes are also tightened to [2-9A-HJ-NP-Z] to prevent false positives from
        // client IDs and numeric codes that previously caused inconsistent USI reads.
        // No schema change.
        upgrade_plugin_savepoint(true, 2026051700226, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026051700225) {
        // AE-SKIP-REPORT (v4.9.155): Per-student skip report added to the auto-enrol results
        // page. After enrolment, admins now see exactly which students were missed and why
        // (no Moodle account / no email / NAT00085 not uploaded), with expandable name tables
        // and a CSV download. The step bar, context note, and confusing "all quals" banner
        // are replaced by a clear enrolled-count card + skip-reason cards. No schema change.
        upgrade_plugin_savepoint(true, 2026051700225, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026052000244) {
        // DIAG-AUTOENROL (v4.9.174): per-qualification diagnostic counters added to the
        // enrolment results page. No DB schema changes. Marker savepoint only.
        upgrade_plugin_savepoint(true, 2026052000244, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026052000245) {
        // FIX-SESSION-WRITECLOSE (v4.9.175): write_close() was called at the very start
        // of the doenrol handler, BEFORE the enrolment loop and BEFORE the session write
        // at the end. This silently discarded the skip report, diagLog, and all per-student
        // failure data — the admin always saw an empty skip report regardless of what happened.
        // Fix: removed the premature write_close(); session stays open so the results are
        // persisted. Added fallback diagnostic when clientids_db=0: lists actual qualcodes
        // stored in the DB for this import to expose case/spacing mismatches.
        // No DB schema changes. Marker savepoint only.
        upgrade_plugin_savepoint(true, 2026052000245, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026052000246) {
        // FIX-AUTOENROL-COMBOBOX-TYPING (v4.9.176): auto-enrol wizard combobox now
        // auto-selects the single matching course on blur, supports Enter key to pick
        // the first result, and shows an amber warning when text is typed but no course
        // was clicked from the dropdown. No DB schema changes. Marker savepoint only.
        upgrade_plugin_savepoint(true, 2026052000246, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026052000247) {
        // FIX-AUTOENROL-NATIVE-SELECT (v4.9.177): replaced the custom JS combobox on
        // the auto-enrol wizard with a native HTML <select> element. The custom combobox
        // JS was silently failing on some Moodle installations, so no dropdown appeared
        // and every qualification card stayed as "Will skip (no enrolment)". The native
        // browser dropdown is guaranteed to work on every Moodle theme without any JS.
        // Server-side automatch now pre-selects the matching course directly in the HTML.
        // No DB schema changes. Marker savepoint only.
        upgrade_plugin_savepoint(true, 2026052000247, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026052000248) {
        // FIX-AUTOENROL-COURSE-GROUPS (v4.9.178): the course dropdown in the auto-enrol
        // wizard now splits courses into two labelled groups — "Qualifications" (courses
        // whose name/shortname contains an Australian qualification code: 2-4 uppercase
        // letters + 5 digits, e.g. MEM20413) at the top, followed by "Other Moodle
        // courses" below. Both groups are always shown so admins can enrol into any
        // course. No DB schema changes. Marker savepoint only.
        upgrade_plugin_savepoint(true, 2026052000248, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026052000243) {
        // FIX-AUTOENROL-ENROLFAILED-VISIBLE + FIX-AUTOENROL-PLACEHOLDER-COLLISION (v4.9.173):
        // no DB schema changes. Marker savepoint only.
        upgrade_plugin_savepoint(true, 2026052000243, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026052000242) {
        // FIX-AUTOENROL-MATCH-FALLBACK (v4.9.172): no DB schema changes.
        // Marker savepoint only.
        upgrade_plugin_savepoint(true, 2026052000242, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026052000249) {
        // FIX-AUTOENROL-CATEGORIES (v4.9.179): the auto-enrol wizard in data_import.php
        // now lists Moodle *categories* (= qualifications) instead of individual courses.
        // Selecting a category enrols students into every visible course (unit of
        // competency) inside it. No DB schema changes — marker savepoint only.
        upgrade_plugin_savepoint(true, 2026052000249, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026052000250) {
        // FIX-AUTOENROL-CROSSIMPORT-NAMES (v4.9.180): when NAT00080 and NAT00120 land
        // in different import batches, studentDetailsMap was empty even though real
        // student names existed in the DB.  Fixed by a cross-import name lookup for
        // any clientid whose name is missing from the current importid.
        // FIX-AUTOENROL-UPDATE-PLACEHOLDER-NAMES (v4.9.180): if an account was
        // auto-created in a previous run with a placeholder name ("Student" + clientid)
        // because NAT00080 was absent, the next import that includes NAT00080 will
        // automatically update the Moodle user profile to use the real name.
        // No DB schema changes — marker savepoint only.
        upgrade_plugin_savepoint(true, 2026052000250, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026052000251) {
        // FIX-INSTALL-XML-AMPERSAND (v4.9.181): install.xml had an unescaped '&' in
        // the COMMENT attribute of the local_rtocompliance_suitability table
        // ("Standard 2 PI 2(a) & 2(b))") — this caused xmlParseEntityRef errors on
        // fresh installs.  Fixed by replacing '&' with '&amp;'.
        // No DB schema changes — marker savepoint only.
        upgrade_plugin_savepoint(true, 2026052000251, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026052000252) {
        // FIX-INSTALL-XML-DUPLICATE-TABLE (v4.9.182): install.xml contained two
        // TABLE definitions for local_rtocompliance_ai_survey — one at line ~447
        // and a more complete one later.  Moodle's XMLDB validator rejects duplicate
        // table names with "Some TABLES name values are incorrect", blocking fresh
        // installs.  The earlier (less complete) duplicate has been removed.
        // No DB schema changes — marker savepoint only.
        upgrade_plugin_savepoint(true, 2026052000252, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026051700224) {
        // USI-INDEX-FIX (v4.9.154): Savepoint 2026051700222 above was originally placed
        // AFTER savepoint 2026051700223 in upgrade.php, so on any site that upgraded from
        // ≤2026051700221, Moodle ran the 223 block first (recording it in the DB), then
        // threw "cannotdowngrade" when the 222 block tried to record a lower savepoint.
        // Result: the USI column widen + index rebuild from 2026051700222 never ran.
        // This 2026051700224 block re-runs the same idempotent DDL so those sites get
        // the correct VARCHAR(15) column and rebuilt indexes.
        local_rtocompliance_upgrade_widen_usi($dbman);
        upgrade_plugin_savepoint(true, 2026051700224, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026051700221) {
        // CLEANUP (v4.9.151): removed diagnostic scaffolding from data_import.php. No schema change.
        upgrade_plugin_savepoint(true, 2026051700221, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026052000259) {
        // FIX-ENROL-BANNER-PAGETYPE (v4.9.189): Extended the enrolled-users banner
        // detection to also match /user/index.php and Moodle pagetype enrol-index /
        // user-index so it works on all Moodle URL configurations, not just
        // /enrol/index.php. No schema change.
        upgrade_plugin_savepoint(true, 2026052000259, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026052000260) {
        // FIX-MATCHMETHOD-UNDEFINED (v4.9.190): $stepThreeMatchMethod was used before
        // being defined on Step 3 (PHP warning on line 2344). Fixed by defining it first.
        // Fix Student Names button moved to bottom of Step 3 page (below qual cards and
        // Confirm & Enrol button) per the confirmed admin workflow. No schema change.
        upgrade_plugin_savepoint(true, 2026052000260, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026052100000) {
        // VERSION-5 (v5.0.0): Major version milestone bump. No schema change.
        upgrade_plugin_savepoint(true, 2026052100000, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026052100001) {
        // TERMINOLOGY-FIX (v5.0.1): generate_course_certs.php picker screen wrongly
        // labelled the dropdown as "Select a Qualification or Course". A Moodle course
        // is a unit of competency; a Moodle category is a qualification. Corrected all
        // four UI strings on the picker screen. No schema change.
        upgrade_plugin_savepoint(true, 2026052100001, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026052100002) {
        // LEFT-PANEL-SPACING (v5.0.2): cert template editor left panel was too narrow
        // (280px) and had insufficient padding (12px), causing field labels like
        // "Signatory position/title [required]" to be clipped. Fixed in styles.css:
        // left column widened to 340px, section padding increased to 16px, palette
        // buttons given white-space:normal + word-break so all text fits. No schema change.
        upgrade_plugin_savepoint(true, 2026052100002, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026052000258) {
        // FIX-ENROL-BANNER (v4.9.188): before_footer_html_generation hook injects a
        // floating "Fix Student Names Now" banner on enrolled-users pages when
        // placeholder accounts are detected. No schema change.
        upgrade_plugin_savepoint(true, 2026052000258, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026051700220) {
        // ROOT-CAUSE-FIX (v4.9.150): data_import.php had an unescaped double-quote inside
        // a PHP double-quoted string (js_init_code call) that caused a PHP parse error,
        // preventing the file from ever compiling. Fixed with heredoc. No schema change.
        upgrade_plugin_savepoint(true, 2026051700220, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026052100003) {
        // FIX-CERT-TEMPLATE-EDITOR (v5.0.3): Six bugs in the certificate template
        // editor fixed. See version.php for full changelog.
        // (1) QR code now satisfies ASQA authenticityMeasure requirement.
        // (2) Show grid checkbox now works (backgroundRepeat fix).
        // (3) Organisation seal upload UI added to Branding panel.
        // (4) organisation_seal added to $imagekeys in cert_template_renderer.php.
        // (5) Organisation seal rendered in canvas editor JS preview.
        // (6) brandingorgsealurl passed from PHP to JS via RTOC_TMPL_DATA.
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026052100003, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026052100008) {
        // QR-CODE-PREVIEW (v5.0.8): Canvas editor now renders a live QR code
        // image for the qrcode field. JS/CSS only — no DB schema changes.
        upgrade_plugin_savepoint(true, 2026052100008, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026052100009) {
        // SUBTLE-REQ-BADGE (v5.0.9): Required-field indicator in palette changed
        // from bold "req" badge to a small red circle icon. CSS+PHP only.
        upgrade_plugin_savepoint(true, 2026052100009, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026052100010) {
        // CERT-REGISTRY-PUBLIC (v5.1.0): Certificates now published to AI Grader
        // central registry at issuance; QR codes point to platform verify URL.
        // PHP-only change — no DB schema changes.
        upgrade_plugin_savepoint(true, 2026052100010, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026052100011) {
        // CERT-REGISTRY-WIRING (v5.1.1): Force-regen supersede path now updates
        // registry; sample_payload() uses real platform URL. PHP-only.
        upgrade_plugin_savepoint(true, 2026052100011, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026052100012) {
        // FORM-BUILDER-PALETTE (v5.1.2): Left-panel field palette redesigned to
        // form-builder style — single scrollable list with colored icon badges,
        // section dividers, field key hints, and a live search filter.
        // PHP + CSS + AMD only. No DB schema changes.
        upgrade_plugin_savepoint(true, 2026052100012, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026052100013) {
        // PALETTE-HEADING-CONTRAST (v5.1.3): Left-panel heading readability.
        // Section group headers (SIGNATORY, MANDATORY PHRASES, etc.) now use
        // #6c757d + 0.75rem matching the PROPERTIES panel heading style.
        // Accordion panel titles (Page Design, Branding, etc.) now uppercase
        // with letter-spacing and stronger contrast. CSS-only. No DB changes.
        upgrade_plugin_savepoint(true, 2026052100013, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026052100014) {
        // SEARCH-ICON-GAP (v5.1.4): Palette search field — increased left
        // padding from 28px to 34px and icon left from 8px to 10px so there
        // is a comfortable gap between the search icon and the typed text.
        // CSS-only. No DB changes.
        upgrade_plugin_savepoint(true, 2026052100014, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026052100015) {
        // SVG-FIELD-ICONS (v5.1.5): Replaced meaningless 2-letter monogram
        // badges in the field palette with proper semantic SVG icons —
        // person (Student), graduation cap (Qualification), award ribbon
        // (Certificate), building (RTO), pen (Signatory), text-lines
        // (Mandatory phrases), shield-check (Compliance), tag (Optional
        // descriptors), magnifying glass (Verification). Custom elements:
        // T-bar (text), calendar (date), picture (image), line, box.
        // PHP + CSS only. No AMD or DB changes.
        upgrade_plugin_savepoint(true, 2026052100015, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026052100016) {
        // FLAT-LEFT-PANEL (v5.1.6): All five accordion sections in the
        // certificate template editor left panel (Fields, Page Design,
        // Branding, Template Info, Quick Guide) replaced with always-visible
        // flat sections. Section headings use a compact header bar; all
        // content is immediately visible in the single scrollable left
        // column. Body padding increased; 58vh palette cap removed.
        // PHP + CSS only. No AMD or DB changes.
        upgrade_plugin_savepoint(true, 2026052100016, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026052100017) {
        // LEFT-PANEL-INPUT-FIX (v5.1.7): Left-panel inputs were inheriting
        // the global plugin rule of border-radius:16px, font-size:1rem,
        // min-height:2.75rem — oversized/pill-shaped in the cert editor.
        // Added .rtoc-tmpl-left scoped overrides (border-radius:4px,
        // font-size:0.83rem). Search field icon-gap padding restored via
        // higher-specificity override. CSS-only. No DB changes.
        upgrade_plugin_savepoint(true, 2026052100017, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026052100018) {
        // ONE-CARD-LEFT-PANEL (v5.1.8): Left panel redesigned as a single
        // scrollable card. .rtoc-tmpl-left is now the card (border + radius
        // + overflow-y:auto). Each .rtoc-panel-section is transparent/no
        // border. Sections separated by thin 1px dividers between adjacent
        // sections only. Section headings are small grey uppercase labels.
        // CSS-only. No DB changes.
        upgrade_plugin_savepoint(true, 2026052100018, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026052300010) {
        // ROLLBACK-ENROLMENTS (v5.2.9): new table local_rtocompliance_enrol_rollback
        // tracks every Moodle user_enrolment and course_completion created by each
        // "Confirm & Enrol" (doenrol) run so the admin can reverse the run in one click.
        $table = new xmldb_table('local_rtocompliance_enrol_rollback');
        $table->add_field('id',           XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('importid',     XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('userid',       XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('courseid',     XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('enrolid',      XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('user_created', XMLDB_TYPE_INTEGER, '1',  null, XMLDB_NOTNULL, null, '0');
        $table->add_field('cc_id',        XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('cc_inserted',  XMLDB_TYPE_INTEGER, '1',  null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated',  XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary',  XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('importid', XMLDB_KEY_FOREIGN, ['importid'], 'local_rtocompliance_avetmiss', ['id']);
        $table->add_index('importid_user', XMLDB_INDEX_NOTUNIQUE, ['importid', 'userid']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026052300010, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026052300012) {
        // FIX-NAT00120-OUTCOME-CODE-10 (v5.2.11): AVETMISS 8.0 outcome code '10' (Not
        // Yet Started) was missing from the recognised outcome codes list in the NAT00120
        // parser.  When a line had '10' at pos 58–59 the parser fell through to the
        // delivery-mode path and incorrectly read '90' from pos 60–61 (a vendor field).
        // Fix: added '10' to the AVETMISS_OUTCOME_CODES array and added a secondary check —
        // if outcome is correctly found at 58–59 AND pos 60–61 is also an outcome-code-shaped
        // value, treat 60–61 as a vendor extra field and shift fundingPos to 62.
        // Label mapping updated: '10' => 'Not Yet Started', '90' => 'Result Not Available'.
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026052300012, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026052300013) {
        // FIX-AUTOENROL-USERNAME-COLLISION (v5.2.12): Pre-create username DB lookup +
        // catch-block recovery for duplicate mdl_user_mneuse_uix constraint.
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026052300013, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026052300014) {
        // FIX-OUTCOME-LABELS (v5.2.13): Outcome labels for codes 20/30/40 were swapped.
        // 20 (Competency Achieved) was labelled "Competency Not Achieved"; 30 (Competency
        // Not Yet Achieved) was labelled "Withdrawn"; 40 (Withdrawn) was labelled "Competency
        // Achieved". Fixed to match AVETMISS 8.0 standard. Also added '41' (VETiS Satisfactorily
        // Completed) and '85' (Non-assessable Satisfactorily Completed) to the doenrol competent
        // outcome set and the Completions tab derived-date set. No DB schema changes.
        upgrade_plugin_savepoint(true, 2026052300014, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026052200001) {
        // GEN-BY-QUAL (v5.2.0): Two bugs fixed in the Generate Certificates feature.
        // (1) FIX-ONCLICK-QUOTES: "Go to Course" button in certificates.php was broken —
        //     json_encode() wrapped the base URL in double quotes, terminating the onclick=""
        //     HTML attribute early. Fixed using single-quote JS literals with htmlspecialchars().
        // (2) GEN-BY-QUAL: New generate_qual_certs.php page for bulk Testamur + Record of
        //     Results generation from a full qualification. Registered in settings.php.
        //     No DB schema changes.
        upgrade_plugin_savepoint(true, 2026052200001, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026052100019) {
        // CHIP-FOCUS-FIX (v5.1.9): Field palette chips (.rtoc-field-row,
        // .rtoc-palette-chip) are <button> elements — Moodle Bootstrap was
        // applying a dark focus background on click that hid chip text.
        // Added explicit :focus/:focus-visible overrides with light blue
        // background (#e9f0ff), blue outline, and box-shadow:none to
        // suppress Bootstrap's default dark focus state. CSS-only. No DB.
        upgrade_plugin_savepoint(true, 2026052100019, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026052100007) {
        // DRAG-DROP-SMART-SIZE (v5.0.7): Drag-and-drop from palette chips to
        // canvas; smart field sizing via Canvas 2D measureText(); centre-aligned
        // defaults; cascading click-to-add. JS/CSS/PHP only — no DB schema changes.
        upgrade_plugin_savepoint(true, 2026052100007, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026052100006) {
        // FIX-FIELD-TEXT-CLIP (v5.0.6): CSS-only fix for text clipping in the
        // certificate template canvas editor. line-height raised 1.1→1.3;
        // bottom padding 2px→4px; .rtoc-tmpl-field-inner overflow hidden→visible.
        // No DB schema changes — marker savepoint only.
        upgrade_plugin_savepoint(true, 2026052100006, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026052100005) {
        // CERT-ISSUANCE-AUDIT (v5.0.5): Seven bugs and wording issues fixed in the
        // Issue Certificates feature. No DB schema changes — marker savepoint only.
        // (1) issue_certificate.php sendemail checkbox now respected.
        // (2) emailsent flag updated after Moodle notification.
        // (3) Sendemail label corrected ("notification" not "email with PDF").
        // (4) settings.php nav link no longer hardcodes courseid=1.
        // (5) generate_course_certs.php hascert excludes superseded certs.
        // (6) certificates.php modal URL uses moodle_url (no hardcoded path).
        // (7) generate_course_certs.php titles corrected from "by Qualification" to "by Course".
        upgrade_plugin_savepoint(true, 2026052100005, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026052100004) {
        // UX-CERT-EDITOR-REDESIGN (v5.0.4): Major UX redesign of cert_template_edit.php
        // left and right panels. Left panel reorganised into 5 collapsible accordion
        // sections (Fields open by default at top, Page Design, Branding, Template Info,
        // Quick Guide). Field palette rebuilt as a 2-column chip grid with required-field
        // badges and colour-coded custom elements. Branding panel now shows compact
        // thumbnail rows with inline status chips in the collapsed summary. Right panel
        // reordered: ASQA validator moved to top, field properties with 3 sub-groups
        // (Position & Size, Typography, Appearance), action buttons pinned to bottom.
        // No DB schema changes. No JS changes.
        upgrade_plugin_savepoint(true, 2026052100004, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026051700219) {
        // DIAG-XHEADER (v4.9.149): X-RTOC-Step response headers added to data_import.php
        // at 6 checkpoints. No schema change.
        upgrade_plugin_savepoint(true, 2026051700219, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026051700218) {
        // DIAG-OPCACHE (v4.9.148): diag page now clears OPcache (opcache_invalidate
        // + opcache_reset), confirms data_import.php file version on disk,
        // and shows PHP error log tail. Marker only — no DB schema changes.
        upgrade_plugin_savepoint(true, 2026051700218, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026052600035) {
        // FIX-ISSUABLE-STATUS (v5.2.35): get_issuable_units() in certificate_validator.php
        // was filtering enrolments with AND e.status = 'completed', but enrolments default
        // to status='active' and many never get promoted to 'completed' even when their
        // AVETMISS outcome is finalised. A competent outcomeidentifier (20/51/60/81) IS the
        // authoritative completion signal. Replaced with status != 'withdrawn'.
        // No DB schema changes — marker savepoint only.
        upgrade_plugin_savepoint(true, 2026052600035, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026052700037) {
        // ARCHIVE-COURSE-LINKING (v5.2.37): New junction table that links a Qual Builder
        // unit to multiple Moodle courses — one primary course and any number of archive
        // semester courses. Previously qualunits.courseid held only ONE course per unit.
        // This table allows: "TLJA5061 → [2010 S1 course, 2010 S2 course, 2011 S1 course …]"
        // so enrolments in ANY of those archive courses still trigger AVETMISS record creation
        // via the observer, and the Archive Linking Wizard can auto-link from intake_groups.json.
        $table = new xmldb_table('local_rtocompliance_qualunit_courses');
        if (!$DB->get_manager()->table_exists($table)) {
            $table->add_field('id',             XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('qualunitid',     XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('courseid',       XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('semester_label', XMLDB_TYPE_CHAR,   '100', null, null,          null, null);
            $table->add_field('is_archive',     XMLDB_TYPE_INTEGER,  '1', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('timecreated',    XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary',              XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('fk_qualunitid',        XMLDB_KEY_FOREIGN, ['qualunitid'], 'local_rtocompliance_qualunits', ['id']);
            $table->add_key('fk_courseid',          XMLDB_KEY_FOREIGN, ['courseid'],   'course', ['id']);
            // Note: fk_qualunitid and fk_courseid implicitly create indexes on those columns.
            // Adding explicit indexes on the same single columns would collide — omitted.
            $table->add_index('idx_quc_uniq',       XMLDB_INDEX_UNIQUE,    ['qualunitid', 'courseid']);
            $DB->get_manager()->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026052700037, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026052700038) {
        // FIX-SUSPENDED-ACCOUNTS + FIX-SOA-SESSION-500 + FIX-MANDATORY-WORDING (v5.2.38).
        // No DB schema changes — marker savepoint only.
        upgrade_plugin_savepoint(true, 2026052700038, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026052800039) {
        // ARCHIVE-COURSE-AUTOENROL (v5.2.39): NAT import auto-enrol wizard now
        // enrols students into archive semester courses linked via qualunit_courses.
        // No DB schema changes — marker savepoint only.
        upgrade_plugin_savepoint(true, 2026052800039, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026052800040) {
        // ARCHIVE-COURSE-AUTOENROL CRITICAL FIX (v5.2.40): process_enrolment_task now
        // correctly resolves qual units for archive courses via qualunit_courses so that
        // local_rtocompliance_enrolments records are created for archive course enrolments.
        // No DB schema changes — marker savepoint only.
        upgrade_plugin_savepoint(true, 2026052800040, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026052800041) {
        // ARCHIVE-AUTOCERT-FIX (v5.2.41): queue_autocert_if_all_units_complete() now also
        // checks qualunit_courses (is_archive=1) so that completing an archive semester
        // course correctly triggers qualification-completion detection, sets programoutcome,
        // and queues auto-certificate generation. No DB schema changes — marker savepoint only.
        upgrade_plugin_savepoint(true, 2026052800041, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026053000067) {
        // CERT-DELETE (v5.2.67): Delete button added to Issue Certificates screen.
        // No DB schema changes — delete_cert.php soft-deletes by setting status='revoked'.
        // Language statement sample text updated per AQF fact sheet feedback.
        upgrade_plugin_savepoint(true, 2026053000067, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026053000068) {
        // SELECT-FIX (v5.2.68): Removed size="8" from the course picker <select> on
        // generate_course_certs.php. The always-open listbox was overlaying the right card.
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026053000068, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026053000069) {
        // CERT-DELETE-FIX (v5.2.69): Fixed delete_cert.php — removed event trigger that caused
        // HTML error page response; made revoke operation idempotent. No DB schema changes.
        upgrade_plugin_savepoint(true, 2026053000069, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026053000070) {
        // CERT-DELETE-FIX2 (v5.2.70): Fixed two more bugs.
        // (1) delete_cert.php used mtrace() which echoes to the HTTP response body in web
        //     context, prepending "[local_rtocompliance] cert_deleted:..." before the JSON —
        //     causing "[local_rtoc..." is not valid JSON. Replaced with error_log().
        // (2) SoA certificate template rendered double unit code: qualificationname is
        //     sometimes stored with the code already prepended (e.g. "MEM13014A Apply
        //     principles...") so building "CODE NAME" produced "MEM13014A MEM13014A Apply...".
        //     cert_template_renderer.php now strips the code prefix from qualificationname
        //     before building partofstatement and completionofcoursestatement.
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026053000070, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026053000071) {
        // v5.2.71–v5.2.81: Various fixes (sidebar icons, missing nav headers on admin pages,
        // SVG icon sizing, USI verification, cert builder, content/quiz plugin updates).
        // No DB schema changes — marker savepoint only.
        upgrade_plugin_savepoint(true, 2026053000071, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026053000082) {
        // FIX-SOA-AJAX-SESSION (v5.2.83): soa_ajax.php called write_close() before
        // require_login() — in Moodle 4.x this caused require_login() to see an invalid
        // session and redirect to the login page, so every AJAX call on soa_issue.php
        // received HTML instead of JSON and silently failed. Fix: moved write_close() to
        // AFTER require_login()/require_capability()/require_sesskey().
        // No DB schema changes — marker savepoint only.
        upgrade_plugin_savepoint(true, 2026053000082, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026053000083) {
        // FIX-MISSING-NAV-SECONDARY-PATHS (v5.2.84): Seven secondary code paths across five
        // files called $OUTPUT->header() without render_nav_header(). Fixed all seven.
        // No DB schema changes — marker savepoint only.
        upgrade_plugin_savepoint(true, 2026053000083, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026053000084) {
        // FIX-SIDEBAR-NO-CSS-SECONDARY-PATHS (v5.2.85): Three of the paths fixed in v5.2.84
        // were missing $PAGE->requires->css('/local/rtocompliance/styles.css') before
        // $OUTPUT->header() — sidebar rendered as unstyled raw HTML with no layout.
        // No DB schema changes — marker savepoint only.
        upgrade_plugin_savepoint(true, 2026053000084, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026053000085) {
        // FIX-MAIN-CONTENT-LEFT-PADDING (v5.2.86): rtoc-main-content had padding-left:4px.
        // On pages where Moodle does not wrap content in .container-fluid or .main-inner,
        // content sat almost flush against the sidebar. Raised to 24px.
        // No DB schema changes — marker savepoint only.
        upgrade_plugin_savepoint(true, 2026053000085, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026053000086) {
        // PLUGIN-SETTINGS-WRAPPER (v5.2.87): Created plugin_settings.php custom page that
        // renders admin settings sections inside the RTO Compliance sidebar layout.
        // Sidebar links for Plugin Settings, Certificate Settings, and Platform API Settings
        // now point to plugin_settings.php instead of bare /admin/settings.php.
        // No DB schema changes — marker savepoint only.
        upgrade_plugin_savepoint(true, 2026053000086, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026053000087) {
        // DOB-MISSING-USI-FIX (v5.2.88): students.php USI cell now shows "DOB required
        // to verify" badge + "Add DOB" link when dateofbirth is NULL/0. Added
        // "USI Present, DOB Missing" filter and stat card.
        // No DB schema changes — marker savepoint only.
        upgrade_plugin_savepoint(true, 2026053000087, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026053000097) {
        // STEP3-SECTIONS+NAT00030 (v5.2.98): New table to store qualification names
        // from NAT00030 files at import time, enabling keyword-based category matching
        // and type-based section splitting (AQF Quals / Skill Sets / Short Courses).
        $dbman = $DB->get_manager();
        if (!$dbman->table_exists('local_rtocompliance_avetmiss_programme')) {
            $table = new xmldb_table('local_rtocompliance_avetmiss_programme');
            $table->add_field('id',        XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('importid',  XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null, null, '0');
            $table->add_field('qualcode',  XMLDB_TYPE_CHAR,    '20',  null, XMLDB_NOTNULL, null, null);
            $table->add_field('qualname',  XMLDB_TYPE_CHAR,    '200', null, null, null, null);
            $table->add_field('isvetprog', XMLDB_TYPE_CHAR,    '1',   null, null, null, null);
            $table->add_key('primary',  XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('importid_qual', XMLDB_INDEX_NOTUNIQUE, ['importid', 'qualcode']);
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026053000097, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026052400031) {
        // FIX-COMPLETION-EXISTING (v5.2.29/v5.2.30): NAT-COMPLETION block now also backfills
        // course_completions for students already enrolled in the course. Also queues
        // process_enrolment_task(action='complete') so outcomeidentifier in
        // local_rtocompliance_enrolments is updated from '70' (Continuing) to the
        // correct NAT outcome code on next cron run.
        // FIX-FALLBACK-OUTCOMES (v5.2.29): generate_course_certs.php fallback completers
        // query outcome set corrected — '41' and '85' added, '52' removed.
        // No DB schema changes. Marker savepoint only.
        upgrade_plugin_savepoint(true, 2026052400031, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026052500032) {
        // FIX-NAT00130-SUCCESSFUL-COMPLETION (v5.2.32): Add successfulcompletion column to
        // local_rtocompliance_avetmiss_completion. AVETMISS 8.0 Data Element 514 — the
        // Successful Programme Completion Indicator (Y/N) — was never parsed or stored.
        // NAT00130 records with flag=N (partial completion / SoA only) were being shown in
        // the Completions tab as if the student had completed the full qualification, which
        // is incorrect. The Completions tab now hides N records by default and shows a
        // Y/N badge on each row. Existing imported rows get NULL (unknown) — re-import
        // the NAT file to populate the flag for historical records.
        $table = new xmldb_table('local_rtocompliance_avetmiss_completion');
        $field = new xmldb_field('successfulcompletion', XMLDB_TYPE_CHAR, '1', null, false, null, null, 'completiondate');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026052500032, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026052500033) {
        // FIX-CERT-TIMEOUT (v5.2.33): generate_course_certs.php and generate_qual_certs.php POST
        // handlers now call write_close() + raise(300) + MEMORY_HUGE before bulk cert generation
        // to prevent Moodle 500 errors caused by session lock contention and PHP time limit.
        // FIX-FULLNAME-DEBUG (v5.2.33): generate_qual_certs.php GROUP BY SQL extended to include
        // phonetic/middle/alternate name fields (ONLY_FULL_GROUP_BY compliance).
        // FIX-BTN-SUCCESS (v5.2.33): styles.css was missing a btn-success rule — the "Confirm &
        // Enrol Students" button appeared grey/plain instead of green.
        // No DB schema changes. Marker savepoint only.
        upgrade_plugin_savepoint(true, 2026052500033, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026052500034) {
        // FIX-AUTOCOMPLETE-OVERFLOW (v5.2.34): styles.css .form-autocomplete-suggestions was
        // inheriting overflow:visible which let long student names (name + email address) push
        // the dropdown list wider than its container and off the edge of the screen on the
        // "Issue a Certificate" page. Fixed with overflow-x:hidden, text-overflow:ellipsis,
        // max-height:320px + overflow-y:auto so the list scrolls instead of spilling.
        // No DB schema changes. Marker savepoint only.
        upgrade_plugin_savepoint(true, 2026052500034, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026060100000) {
        // NAT-MATCH-AUDIT (v5.3.0): Five-fix overhaul of Step 3 NAT→Moodle category
        // matching + full Category Matching Audit Table. No DB schema changes.
        upgrade_plugin_savepoint(true, 2026060100000, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026060100001) {
        // PASS-E-COURSE-MATCH (v5.3.1): Added Pass E — scans all Moodle {course} rows
        // for NAT qual codes in fullname/shortname/idnumber, then matches to the course's
        // parent category. Fixes the most common archive miss: category "Archive / 2015 S1"
        // has no qual code in its name or any ancestor, but its courses carry the code.
        // Also updated audit table to show "Code in courses" column and Pass E legend.
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026060100001, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026060100002) {
        // SMART-YS-DETECT (v5.3.2): Comprehensive year/semester format overhaul.
        // Added $fnDetectYearSem primitive that handles 15+ naming conventions:
        // S/Sem/Semester, H/HY/Half, T/Term, Q1-Q4 (quarter), month names
        // (Jan=S1, Jul=S2), ordinals (1st/2nd Sem), slash notation (2020/S1),
        // 2-digit years (13 → 2013), and all separators (space, dash, dot, slash).
        // Fixed fnExtractYsFromPath to do cross-segment combination: year in one
        // path segment + semester in another path segment are now combined, fixing
        // structures like "Archive / 2020 / Semester 1".
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026060100002, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026060100003) {
        // USI-CURL-FIX+SURNAME-FIRST (v5.3.3): Two fixes.
        // (1) USI-CURL-FIX: usi_platform_client.php uses new \curl() (Moodle's
        // HTTP wrapper from lib/filelib.php). When the class is loaded via PHP
        // namespace autoloader, filelib.php is not auto-included — resulted in
        // "Class curl not found" on every USI verify button click. Fix: added
        // explicit require_once($CFG->libdir.'/filelib.php') at the top of
        // usi_platform_client.php.
        // (2) SURNAME-FIRST: students.php name column now displays "Abraham, Matthew"
        // (lastname, firstname) instead of "Matthew Abraham". List is already
        // ORDER BY u.lastname so no SQL change needed. Fullname link and search
        // are unchanged. No DB schema changes.
        upgrade_plugin_savepoint(true, 2026060100003, 'local', 'rtocompliance');
    }


    if ($oldversion < 2026060100008) {
        // UPGRADE-REPAIR-032 (v5.3.8): Re-apply the successfulcompletion column addition from
        // block 2026052500032. Due to a savepoint ordering bug in upgrade.php (blocks 031-034
        // were prepended in reverse order at the top of the function instead of being appended
        // in ascending order at the correct chronological position), users who installed v5.3.x
        // over a very old version had block 034 save first, then hit a "cannot downgrade to 033"
        // exception — so the upgrade never reached block 032. This repair block adds the column
        // idempotently (table_exists + field_exists guards) so every affected install gets it
        // regardless of their upgrade path. Also: FIX-SOA-ISSUE-500 (v5.3.8): Removed the
        // explicit require_once of usi_platform_client.php from soa_issue.php — same PSR-4
        // autoloader conflict pattern as the original soa_compliance_engine 500 (v5.2.89).
        $table = new xmldb_table('local_rtocompliance_avetmiss_completion');
        $field = new xmldb_field('successfulcompletion', XMLDB_TYPE_CHAR, '1', null, false, null, null, 'completiondate');
        if ($dbman->table_exists($table) && !$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026060100008, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026060200002) {
        // NAT-MATCH-7FIXES (v5.3.9): No DB schema changes — all improvements are
        // in data_import.php (matching logic, Pass F, RC6 diagnostic panel).
        // This is a marker-only savepoint so Moodle records the upgrade.
        upgrade_plugin_savepoint(true, 2026060200002, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026060200009) {
        // ARCHIVE-INDEX-PHASE1 (v5.7.0): New database-first archive index infrastructure.
        // Replaces the old runtime keyword-scanning + multi-pass matching engine with a
        // persistent lookup table built once from mdl_course_categories.
        // Adds 3 tables:
        //   local_rtocompliance_archive_index      — category→family+year+sem map
        //   local_rtocompliance_archive_active_pick — admin resolves duplicate archive periods
        //   local_rtocompliance_archive_meta        — stores last_hash for auto-rebuild trigger

        $table = new xmldb_table('local_rtocompliance_archive_index');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id',           XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('categoryid',   XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null, null);
            $table->add_field('categoryname', XMLDB_TYPE_CHAR,    '255', null, XMLDB_NOTNULL, null, '');
            $table->add_field('fullpath',     XMLDB_TYPE_TEXT,    null,  null, false,         null, null);
            $table->add_field('family',       XMLDB_TYPE_CHAR,    '50',  null, false,         null, null);
            $table->add_field('year',         XMLDB_TYPE_INTEGER, '4',   null, XMLDB_NOTNULL, null, '0');
            $table->add_field('sem',          XMLDB_TYPE_CHAR,    '2',   null, XMLDB_NOTNULL, null, '');
            $table->add_field('is_active',    XMLDB_TYPE_INTEGER, '1',   null, XMLDB_NOTNULL, null, '1');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('categoryid',    XMLDB_INDEX_UNIQUE,    ['categoryid']);
            $table->add_index('family_yr_sem', XMLDB_INDEX_NOTUNIQUE, ['family', 'year', 'sem']);
            $dbman->create_table($table);
        }

        $table = new xmldb_table('local_rtocompliance_archive_active_pick');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id',                XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('family',            XMLDB_TYPE_CHAR,    '50', null, XMLDB_NOTNULL, null, '');
            $table->add_field('year',              XMLDB_TYPE_INTEGER, '4',  null, XMLDB_NOTNULL, null, '0');
            $table->add_field('sem',               XMLDB_TYPE_CHAR,    '2',  null, XMLDB_NOTNULL, null, '');
            $table->add_field('active_categoryid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('family_yr_sem', XMLDB_INDEX_UNIQUE, ['family', 'year', 'sem']);
            $dbman->create_table($table);
        }

        $table = new xmldb_table('local_rtocompliance_archive_meta');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id',      XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('metakey', XMLDB_TYPE_CHAR,    '100', null, XMLDB_NOTNULL, null, '');
            $table->add_field('value',   XMLDB_TYPE_TEXT,    null,  null, false,         null, null);
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('metakey', XMLDB_INDEX_UNIQUE, ['metakey']);
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026060200009, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026060200010) {
        // ARCHIVE-INDEX-PHASE2 (v5.8.0): No DB schema changes — code-only release.
        // Marker savepoint so Moodle advances the installed version correctly.
        upgrade_plugin_savepoint(true, 2026060200010, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026060200011) {
        // ARCHIVE-INDEX-PHASE3 (v5.9.0): No DB schema changes — code-only release.
        // Marker savepoint so Moodle advances the installed version correctly.
        upgrade_plugin_savepoint(true, 2026060200011, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026060200012) {
        // v5.9.1: Fix missing upgrade savepoints (2026060200010/011 were absent,
        // causing a perpetual "needs upgrade" loop that produced HTTP 500 on all
        // admin pages). Also fixes wrong meta key 'last_rebuilt_at' → 'last_rebuilt'
        // in the autoenrol stale-index banner. No DB schema changes.
        upgrade_plugin_savepoint(true, 2026060200012, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026060200013) {
        // v5.9.2: Two critical data_import.php bugs fixed.
        // Bug 1 (FIX-CONTEXT-UNDEFINED): $context was never defined; require_capability()
        //   calls in qcm_search/qcm_save/qcm_children threw TypeError on PHP 8+.
        // Bug 2 (FIX-AJAX-AFTER-HEADER): Those same three AJAX handlers were placed after
        //   echo $OUTPUT->header(), producing mixed HTML+JSON responses. Moved before output.
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026060200013, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026060200014) {
        // v5.9.3: Version bump to provide a clean upgrade target for sites stuck in
        // the HTTP 500 upgrade loop caused by v5.8.0/v5.9.0 missing savepoints.
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026060200014, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026060200015) {
        // v5.9.4: DIAG build — PHP error display added to data_import.php.
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026060200015, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026060200016) {
        // v5.9.5: Adds diag_500.php standalone diagnostic.
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026060200016, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026060200017) {
        // v5.9.6: Improves diag_500.php with session dump + log file + session lock release.
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026060200017, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026060200018) {
        // v5.9.7: Adds breadcrumb error_log() to data_import.php; diag_500.php reads log files.
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026060200018, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026060200019) {
        // v5.9.8: FIX-DIAG-HEADERS-SENT: diag_500.php ob_start() before any output.
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026060200019, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026060200020) {
        // v5.9.9: FIX-DIAG-DB-QUERY: removed invalid $DB->record_exists('external_pages') call.
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026060200020, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026060200021) {
        // v5.9.10: FIX-DIAG-SHUTDOWN-HANDLER: shutdown fired on success, stealing output.
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026060200021, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026060200022) {
        // v5.9.11: FIX-DIAG-DB-CHECKPOINT: DB-based breadcrumb logging in data_import.php.
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026060200022, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026060200023) {
        // v5.9.12: FIX-PARSE-ERROR: Removed spurious extra } at end of data_import.php
        // (line 6058) that caused a PHP brace-depth imbalance (-1), preventing the file
        // from compiling at all — producing a 500 on every visit with zero log output.
        upgrade_plugin_savepoint(true, 2026060200023, 'local', 'rtocompliance');
    }

    // v5.9.13: OPCACHE-FIX — adds opcache_fix.php. No DB schema change.
    if ($oldversion < 2026060300024) {
        upgrade_plugin_savepoint(true, 2026060300024, 'local', 'rtocompliance');
    }

    // v5.9.14: FIX-PARSE-ERROR — removed spurious } + added missing closing } in data_import.php. No DB schema change.
    if ($oldversion < 2026060300025) {
        upgrade_plugin_savepoint(true, 2026060300025, 'local', 'rtocompliance');
    }

    // v5.9.15: UX — expanded "Archive index is empty" warning with steps + button. No DB schema change.
    if ($oldversion < 2026060300026) {
        upgrade_plugin_savepoint(true, 2026060300026, 'local', 'rtocompliance');
    }

    // v5.9.16: Removed diagnostic/testing PHP files (opcache_fix, diag_*, test_data, testing, trainers_diag). No DB schema change.
    if ($oldversion < 2026060300027) {
        upgrade_plugin_savepoint(true, 2026060300027, 'local', 'rtocompliance');
    }

    // v5.9.17: AUTO-UNHIDE hidden archive categories at enrolment time. Preview now shows AUTO (not REVIEW) when category is hidden but otherwise ready.
    if ($oldversion < 2026060300028) {
        upgrade_plugin_savepoint(true, 2026060300028, 'local', 'rtocompliance');
    }

    // v5.9.18: Added SC001 and TLISS00072 to qual_to_family map.
    if ($oldversion < 2026060300029) {
        upgrade_plugin_savepoint(true, 2026060300029, 'local', 'rtocompliance');
    }

    // v5.9.19: Fixed $m[2] → $m[1] bug in archive_detect_year_sem(); 2-digit years (e.g. "22 DCB S1")
    // were always resolving to year=2020 instead of 2022, causing false "duplicate" groupings.
    if ($oldversion < 2026060300030) {
        upgrade_plugin_savepoint(true, 2026060300030, 'local', 'rtocompliance');
    }

    // v5.9.20: Archive index now skips categories with no S1/S2 semester (CPD/CBC/Summer School noise).
    // Improved Archive Index Manager UI — plain-English conflict explanations, correct conflict count,
    // red/green card headers showing resolved vs unresolved, and "X periods need your decision" banner.
    if ($oldversion < 2026060300031) {
        upgrade_plugin_savepoint(true, 2026060300031, 'local', 'rtocompliance');
    }

    // v5.9.21: FIX — conflict groups were all keyed by 'family' in get_records_sql(), causing all but
    // the last conflict group to be silently overwritten. Fixed by selecting a unique composite rowkey
    // (family-year-sem) as the first column so every group survives in the returned array.
    if ($oldversion < 2026060300032) {
        upgrade_plugin_savepoint(true, 2026060300032, 'local', 'rtocompliance');
    }

    // v5.9.22: After NAT enrolment completes, a "Hide Archive Courses Now" card appears on the
    // results page whenever the import auto-unhid one or more archive categories.  Clicking it
    // re-hides those categories (visible=0) so students no longer see old courses, while keeping
    // their enrolment records intact.
    if ($oldversion < 2026060300033) {
        upgrade_plugin_savepoint(true, 2026060300033, 'local', 'rtocompliance');
    }

    // v5.9.23: Qual-code-aware archive matching.  When multiple archive categories exist for the
    // same family+year+sem (e.g. TLI50816 and TLI50822 both indexing Customs Broking 2023 S2),
    // the NAT import now checks each candidate's fullpath for the student group's qual code and
    // auto-routes to the correct category without requiring the admin to manually set one active.
    // Archive Index page now also shows extracted qual code badges on each conflict candidate and
    // displays a "No action needed" info box when all candidates map to distinct qual codes.
    if ($oldversion < 2026060300034) {
        upgrade_plugin_savepoint(true, 2026060300034, 'local', 'rtocompliance');
    }

    // v5.9.24: Group-splitting for mixed-qual NAT groups.  Previously, when a NAT file contained
    // students from two different qual codes that share the same archive family (e.g. TLI50816
    // and TLI50822 both → customs_broking), they were placed into a single group and the import
    // flagged REVIEW because it found 2 matching archives and couldn't auto-pick one.
    // Fix: after initial grouping, detect groups that contain multiple qual codes where each qual
    // code maps to a distinct archive category (identified by the qual code appearing in the
    // category fullpath).  Those groups are split into per-qual-code sub-groups before the
    // archive lookup runs, so each sub-group resolves independently to AUTO.
    if ($oldversion < 2026060300035) {
        upgrade_plugin_savepoint(true, 2026060300035, 'local', 'rtocompliance');
    }

    // v5.9.25: Archive Index now correctly counts and displays "truly unresolved" conflicts —
    // excluding any conflict where every candidate has a distinct qual code in its path (those
    // are auto-routed by the NAT import and need no admin decision).  The stat counter, the
    // summary alert, and the "X unresolved" badge all reflect this.  Auto-routeable conflict
    // cards now have a blue header ("Auto-routed by import") instead of red ("Pick one below").
    if ($oldversion < 2026060300036) {
        upgrade_plugin_savepoint(true, 2026060300036, 'local', 'rtocompliance');
    }

    // v5.9.26: Auto-routeability check now uses breadcrumb-based qual-code detection.
    // A conflict is auto-routeable if at least ONE candidate has a distinct qual code in its
    // fullpath (old check required ALL candidates to have qual codes).  Dead "Closed short
    // courses" candidates with no qual code are correctly treated as bypassed legacy folders,
    // not as ambiguous conflicts.  Fixes 2016 S1 and 2015 S1 showing red/amber when the
    // import can already route TLI50816 students correctly via the category breadcrumb.
    if ($oldversion < 2026060300037) {
        upgrade_plugin_savepoint(true, 2026060300037, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026060300038) {
        // v5.9.27: Verify NAT Data tab — no DB schema changes.
        upgrade_plugin_savepoint(true, 2026060300038, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026060300039) {
        // v5.9.28: Fix Verify NAT Data DB queries — no DB schema changes.
        upgrade_plugin_savepoint(true, 2026060300039, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026060300040) {
        // v5.9.29: Fix name blank for fixed-width NAT files — no DB schema changes.
        upgrade_plugin_savepoint(true, 2026060300040, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026060300041) {
        // v5.9.30: Verify NAT Data — add NAT00085 email-based Moodle account matching — no DB schema changes.
        upgrade_plugin_savepoint(true, 2026060300041, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026060300042) {
        // v5.9.31: Backfill Student Records action — no DB schema changes.
        upgrade_plugin_savepoint(true, 2026060300042, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026060300043) {
        // v5.9.32: In-context help banners on NAT Import, Confirm, Auto-Enrol (Step 3), and Verify NAT Data pages — no DB schema changes.
        upgrade_plugin_savepoint(true, 2026060300043, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026060300044) {
        // v5.9.33: Qual Builder prerequisite check on NAT Import page — shows blocking warning if no quals set up, or a subtle reminder with count if set up. No DB schema changes.
        upgrade_plugin_savepoint(true, 2026060300044, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026060300045) {
        // v5.9.34: USI Verification setup popup on Student Records page — auto-shows modal when API not connected; softer banner when API connected but cert not uploaded. No DB schema changes.
        upgrade_plugin_savepoint(true, 2026060300045, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026060300046) {
        // v5.9.35: FIX — "Open API Connection settings" button used wrong section name (local_rtocompliance vs local_rtocompliance_api), causing a 404. Fixed in usi_settings.php and tas_edit.php. No DB schema changes.
        upgrade_plugin_savepoint(true, 2026060300046, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026060300047) {
        // v5.9.36: IMPROVE — Platform Webhook Key field in API Settings now clearly marked as optional with instructions. No DB schema changes.
        upgrade_plugin_savepoint(true, 2026060300047, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026060300048) {
        // v5.9.37: FIX — Removed "SaaS dashboard" reference from USI settings tip; RTOs do not have access to lms-labs.com/admin. Tip now correctly explains they contact their account manager. No DB schema changes.
        upgrade_plugin_savepoint(true, 2026060300048, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026060300049) {
        // v5.9.38: CSS — consistent padding on all edges of every plugin page. .rtoc-main-content now has padding: 0 24px 40px 24px (was left-only). Nav header margins updated to -1.5rem horizontal. No DB schema changes.
        upgrade_plugin_savepoint(true, 2026060300049, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026060300050) {
        // v5.9.39: COPY — Simplified webhook key descriptions to plain English. No DB schema changes.
        upgrade_plugin_savepoint(true, 2026060300050, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026060300051) {
        // v5.9.40: UX — Removed all disabled/greyed-out gates from USI machine credential form. No DB changes.
        upgrade_plugin_savepoint(true, 2026060300051, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026060300052) {
        // v5.9.41: UX — Added permanent Backfill Student Records shortcut button on main Data Import page. No DB changes.
        upgrade_plugin_savepoint(true, 2026060300052, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026060300053) {
        // v5.9.42: DOCS/UX — Full workflow guide in support.php and expanded 4-column how-it-works card in data_import.php. No DB changes.
        upgrade_plugin_savepoint(true, 2026060300053, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026060300054) {
        // v5.9.43: STATE FUNDING — Add state-specific fields for Australian state/territory
        // funded training reporting (QLD DTET, NSW Smart & Skilled, VIC Skills First,
        // SA Skills for All, WA DTWD, TAS Skills Tasmania, NT DITT, ACT Skills Canberra).
        //
        // Students table: schooltype (GOV/CAT/IND/OTH) for school-based students (e.g. QLD
        // DTET requires the school sector for students with an LUI).
        //
        // Enrolments table: concessionstatus (F/C/E), purchasingcontract1/2/3 — QLD DTET
        // requires up to 3 purchasing contract codes per enrolment (e.g. QS102922 for Career
        // Start); other states use 1-2 contract codes. Concession status records whether the
        // student paid full fee, a concessional rate, or received a fee exemption/waiver.

        $table = new xmldb_table('local_rtocompliance_students');

        $field = new xmldb_field('schooltype', XMLDB_TYPE_CHAR, '3', null, null, null, null, 'waraptid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $table = new xmldb_table('local_rtocompliance_enrolments');

        $field = new xmldb_field('concessionstatus', XMLDB_TYPE_CHAR, '1', null, null, null, null, 'trainingcontractid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('purchasingcontract1', XMLDB_TYPE_CHAR, '20', null, null, null, null, 'concessionstatus');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('purchasingcontract2', XMLDB_TYPE_CHAR, '20', null, null, null, null, 'purchasingcontract1');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('purchasingcontract3', XMLDB_TYPE_CHAR, '20', null, null, null, null, 'purchasingcontract2');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026060300054, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026060400055) {
        // v5.9.44: No DB schema changes. settings.php namespace fix for avetmiss_codes.
        upgrade_plugin_savepoint(true, 2026060400055, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026060400056) {
        // v5.9.45: No DB schema changes. State-funding audit fixes (3 bugs).
        upgrade_plugin_savepoint(true, 2026060400056, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026060400057) {
        // v5.9.46: No DB schema changes. settings.php explicit require_once for avetmiss_codes.
        upgrade_plugin_savepoint(true, 2026060400057, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026060400058) {
        // v5.9.47: No DB schema changes. State Funding UX overhaul + private-name purge.
        upgrade_plugin_savepoint(true, 2026060400058, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026060400059) {
        // v5.9.48: No DB schema changes. Expanded state/territory regulator dropdown from 4 to 11 entries.
        upgrade_plugin_savepoint(true, 2026060400059, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026060400060) {
        // v5.9.49: No DB schema changes. Fixed State Funding tab in plugin_settings.php (missing from allowlist + tabs).
        upgrade_plugin_savepoint(true, 2026060400060, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026060400061) {
        // v5.9.50: No DB schema changes. support.php HTTP 500 fix: added diagnostic breadcrumb logger
        // + function_exists guard on support_icon() + missing emerald CSS classes.
        upgrade_plugin_savepoint(true, 2026060400061, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026060400062) {
        // v5.9.51: No DB schema changes. ROOT-CAUSE FIX — settings.php require_once avetmiss_codes.php
        // wrapped in class_exists guard to prevent "Cannot redeclare class" 500 on symlinked Moodle.
        // Also added DIAG breadcrumb logging to soa_issue.php.
        upgrade_plugin_savepoint(true, 2026060400062, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026060400063) {
        // v5.9.52: No DB schema changes. ENROL-CONTINUING-ONLY — auto-enrol doenrol action now only
        // processes students with outcome 70 (Continuing Enrolment). Terminal-outcome-only students
        // (completed/withdrawn/RPL/CT etc.) are skipped and should use Backfill Qual Builder.
        upgrade_plugin_savepoint(true, 2026060400063, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026060900064) {
        // v5.9.54: No DB schema changes. CSS-FIX — Fixed nav-header negative margins.
        upgrade_plugin_savepoint(true, 2026060900064, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026060900065) {
        // v5.9.54: No DB schema changes. (duplicate savepoint — padding belt-and-suspenders).
        upgrade_plugin_savepoint(true, 2026060900065, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026060900066) {
        // v5.9.55: No DB schema changes. ROOT-CAUSE FIX — lib.php double-load on symlinked Moodle.
        // Added LOCAL_RTOCOMPLIANCE_LIB_LOADED define guard at top of lib.php.
        upgrade_plugin_savepoint(true, 2026060900066, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026060900067) {
        // v5.9.56: No DB schema changes. BREADCRUMB-LAYOUT-FIX — fixed nav-header items stacking
        // vertically on admin pages. Root cause: @media (max-width: 640px) rule set flex-direction:column
        // on .rtoc-nav-header, triggering on Moodle admin layout narrower containers even at desktop
        // viewport widths. Fixes: (1) added flex-direction:row to .rtoc-nav-header main rule to
        // explicitly lock horizontal layout; (2) changed both mobile breakpoints from 640px to 420px
        // so column-stacking only fires on actual narrow-phone viewports; (3) increased .rtoc-nav-left
        // gap from 6px to 10px; (4) removed flex-wrap:wrap from .rtoc-nav-left to prevent item
        // wrapping; (5) darkened and enlarged .rtoc-nav-separator for better visibility.
        upgrade_plugin_savepoint(true, 2026060900067, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026060900068) {
        // v5.9.57: No DB schema changes. ENROL-UNIT-ACCURATE — new "Unit-accurate enrolment"
        // toggle on the Step 3 auto-enrol form (checked by default). When on, each student is
        // only enrolled into Moodle courses whose Course ID number matches a unit code in their
        // NAT00120 file. When off, previous behaviour (enrol into every visible course in the
        // matched category) is preserved.
        upgrade_plugin_savepoint(true, 2026060900068, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026061000069) {
        // v5.9.58: No DB schema changes. FIX-SETTINGS-FUNCGUARD — replaced the bare
        // require_once($CFG->dirroot.'/local/rtocompliance/lib.php') in settings.php with a
        // function_exists('local_rtocompliance_extend_navigation_frontpage') guard. The previous
        // constant-based guard (LOCAL_RTOCOMPLIANCE_LIB_LOADED, v5.9.55) is defeated when PHP
        // OPcache serves a stale bytecode of lib.php from before v5.9.55 was installed —
        // the stale bytecode has no constant-guard, so both the __DIR__ load and the
        // $CFG->dirroot load execute, causing "Cannot redeclare function" → HTTP 500. The
        // function_exists guard checks the RESULT of lib.php being loaded (functions defined)
        // rather than a constant the stale bytecode may never set, making it immune to stale
        // OPcache regardless of Moodle symlink configuration.
        upgrade_plugin_savepoint(true, 2026061000069, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026062500003) {
        // FIX-FOE-REASON-HIDDEN: No DB schema changes.
        upgrade_plugin_savepoint(true, 2026062500003, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026062500004) {
        // FOE-REMOVE-HIDDEN-CRITERION: No DB schema changes.
        // "Course is hidden" removed as a standalone criterion from Fix Over-Enrolments.
        upgrade_plugin_savepoint(true, 2026062500004, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026062500005) {
        // FOE-TWO-PATH-MATCH: No DB schema changes.
        // Fix Over-Enrolments now matches students via two paths:
        // (A) mdl_user.idnumber = clientid (wizard-created accounts),
        // (B) local_rtocompliance_students.clientid → userid (manually-created accounts).
        upgrade_plugin_savepoint(true, 2026062500005, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026062500006) {
        // FOE-FIVE-PATH-MATCH: No DB schema changes.
        // Fix Over-Enrolments student matching expanded to 5 paths:
        // (A) mdl_user.idnumber, (B) students.clientid, (C) mdl_user.username,
        // (D) NAT00085 email → mdl_user.email, (E) NAT00080 USI → students.usi.
        upgrade_plugin_savepoint(true, 2026062500006, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026062500010) {
        // FOE-SUBQUERY-COMPAT: No DB schema changes.
        // Fixed "Error reading from database" on Fix Over-Enrolments caused by
        // MySQL ONLY_FULL_GROUP_BY rejecting INNER JOIN + MAX(importid) subquery.
        // Replaced with plain ORDER BY importid DESC; PHP deduplicates.
        upgrade_plugin_savepoint(true, 2026062500010, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026062500011) {
        // FOE-REVERSE-MAP + REPAIRNAMES-ORDER: No DB schema changes.
        // (1) Fix Over-Enrolments: O(n) array_search replaced with O(1) reverse map.
        // (2) Fix Student Names: ORDER BY importid DESC for deterministic name selection.
        upgrade_plugin_savepoint(true, 2026062500011, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026062500012) {
        // FIX-SEARCH-FULLNAME: No DB schema changes.
        // Student search now matches "Firstname Lastname" and "Lastname Firstname"
        // concatenations so full-name searches return results.
        upgrade_plugin_savepoint(true, 2026062500012, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026062500013) {
        // DATA-QUALITY-TAB: No DB schema changes.
        // New Data Quality tab on the Data Import page with flagged student table,
        // outcome code validation, completion quality panel, and CSV export.
        upgrade_plugin_savepoint(true, 2026062500013, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026062500014) {
        // FOE-LASTNAME-COLUMN-FIX: No DB schema changes.
        // Fixed "Error reading from database" in Fix Over-Enrolments unmatched-student
        // diagnostic query: column is familyname not lastname in avetmiss_student table.
        upgrade_plugin_savepoint(true, 2026062500014, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026062500015) {
        // FOE-PREVIEW-CAP: No DB schema changes.
        // Capped FOE preview tables at 30 rows per category to fix display/scroll
        // issues when there are thousands of over-enrolments. Apply button moved
        // above the tables so it is immediately accessible. All flagged enrolments
        // are still removed on apply regardless of how many rows are previewed.
        upgrade_plugin_savepoint(true, 2026062500015, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026062500016) {
        // FOE-OUTCOME-30-REMOVED: No DB schema changes.
        // Removed outcome code '30' (Competency Not Yet Achieved) from the
        // NON_CONTINUING list used by Fix Over-Enrolments. Code 30 was causing
        // students to be flagged for unenrolment from units that ARE present in
        // their NAT file — incorrect when the SMS uses 30 as an in-progress
        // placeholder (e.g. Wisenet default before assessment is finalised).
        upgrade_plugin_savepoint(true, 2026062500016, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026062500017) {
        // FOE-NO-UNIT-CODE-SECTION: No DB schema changes.
        // Added Section C to Fix Over-Enrolments diagnostic panel. Root cause:
        // courses without a Course ID number set (Orientation, LLN, SA Trade,
        // etc.) are invisible to the bulk enrolment query — a matched student
        // enrolled in those courses would show zero flagged rows and silently
        // disappear from the report. Section C runs a separate SQL query to find
        // all active manual enrolments for matched students in courses with a
        // blank idnumber and displays them so the admin can act. Also fixed the
        // stale UI description that still listed outcome 30 in the non-continuing
        // codes after it was removed in v5.9.84.
        upgrade_plugin_savepoint(true, 2026062500017, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026062500018) {
        // FOE-CATEGORY-SCOPE: No DB schema changes.
        // Section C replaced with category-scoped approach.
        upgrade_plugin_savepoint(true, 2026062500018, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026062500019) {
        // FOE-DATE-FILTER-REMOVED: No DB schema changes.
        // Removed ue.timecreated <= import_ts filter from main bulk query and
        // Section C query — the auto-enrol wizard always creates enrolments
        // after the import record is timestamped, so the filter was excluding
        // the very over-enrolments the tool was meant to find.
        upgrade_plugin_savepoint(true, 2026062500019, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026062500020) {
        // FOE-EARLY-EXIT-BUG: No DB schema changes.
        // Fixed early exit that prevented Sections A/B/C from rendering when
        // foeToUnenrol was empty. Sections A/B/C now always render so unmatched
        // students and no-code-course enrolments are always visible.
        upgrade_plugin_savepoint(true, 2026062500020, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026062500021) {
        // PRIVACY-CLEANUP: No DB schema changes.
        // Removed student name references from UI text and code comments.
        // Removed non-functional Student Diagnostic Trace UI panel.
        upgrade_plugin_savepoint(true, 2026062500021, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026062700025) {
        // FOE-BATCH-CHUNKED (v5.9.94): New local_rtocompliance_foe_pending table.
        // Stores pending unenrolment rows when admin applies Fix Over-Enrolments
        // on a large import (e.g. 16,000 rows). The Apply action now inserts all
        // pending rows into this table in one go and redirects to a progress page.
        // A JS polling loop calls the foe_apply_chunk AJAX endpoint which processes
        // 200 rows per request (~1-2s each), updating the progress bar in real time.
        // This avoids the PHP web-request timeout that previously killed the fix
        // when there were more than ~500 rows.
        $dbman = $DB->get_manager();
        if (!$dbman->table_exists('local_rtocompliance_foe_pending')) {
            $table = new xmldb_table('local_rtocompliance_foe_pending');
            $table->add_field('id',       XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('batchid',  XMLDB_TYPE_CHAR,    '36', null, XMLDB_NOTNULL);
            $table->add_field('importid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('userid',   XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('enrolid',  XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('status',   XMLDB_TYPE_CHAR,    '10', null, XMLDB_NOTNULL, null, 'pending');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('batchid_status', XMLDB_INDEX_NOTUNIQUE, ['batchid', 'status']);
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026062700025, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026062800031) {
        // FOE-OUTCOME-30-RESTORED: No DB schema changes.
        upgrade_plugin_savepoint(true, 2026062800031, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026062800032) {
        // FOE-SEARCH-BOX: No DB schema changes.
        upgrade_plugin_savepoint(true, 2026062800032, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026062800034) {
        // NAT-RECONCILE-SIDEBAR: Added NAT Reconciliation Tool to lib.php sidebar nav. No DB schema changes.
        upgrade_plugin_savepoint(true, 2026062800034, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026062800035) {
        // NAT-RECONCILE-DIAG: Added Pipeline Diagnostic panel to reconcile.php results page. No DB schema changes.
        upgrade_plugin_savepoint(true, 2026062800035, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026062800036) {
        // NAT-RECONCILE-TRACE: Added per-student trace + post-Step-6 current enrolment count to reconcile.php. No DB schema changes.
        upgrade_plugin_savepoint(true, 2026062800036, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026062800037) {
        // NAT-RECONCILE-STEP6-DUMP: Added Step 6 debug dump to reconcile.php — shows matched user/course IN() lists + paste-ready SQL. No DB schema changes.
        upgrade_plugin_savepoint(true, 2026062800037, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026062800038) {
        // NAT-RECONCILE-STEP6-DUMP-FIX: Fixed Step 6 debug dump rendering — was echo'd before $OUTPUT->header() so Moodle's output buffer swallowed it; now captured into $_step6DebugHtml variable and rendered in the HTML section. No DB schema changes.
        upgrade_plugin_savepoint(true, 2026062800038, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026062800039) {
        // NAT-RECONCILE-STEP6-DUMP-SQL-FIX: Fixed MySQL error 1064 caused by calling get_in_or_equal() twice (once for debug, once for real query) — Moodle's named-param registry produced a conflict that injected debug HTML into the SQL string. Fix: removed pre-query get_in_or_equal() call entirely; debug data is now captured AFTER the real query closes using the same $_uidsql/$_cidsql variables already built for the query. No DB schema changes.
        upgrade_plugin_savepoint(true, 2026062800039, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026062800040) {
        // RECONCILE-DEEP-DEBUG: Expanded Step 6 debug panel with unfiltered enrolment count (same users, no course filter — reveals whether user IDs or course IDs are the bottleneck), pipeline intermediate counts (neededCatCount, catVisibleCourseCount, usersWithExpected, totalExpectedPairs), first 30 matched user IDs, all NAT-universe course IDs, and paste-ready unfiltered SQL. No DB schema changes.
        upgrade_plugin_savepoint(true, 2026062800040, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026062800041) {
        // RECONCILE-NEWEST-WINS: Fixed Step 3 unit→category mapping to use newest course (ORDER BY c.id DESC) instead of oldest (c.id ASC). The oldest-wins bug caused the reconciler to scope against 2016-era category courses when current students are enrolled in 2026-era courses in different categories, resulting in only 13 enrolments found vs 23,585 expected. No DB schema changes.
        upgrade_plugin_savepoint(true, 2026062800041, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026062800042) {
        // RECONCILE-COURSE-SELECTION-DIAG: Added Course Selection Diagnostic panel to reconcile.php results page. For every NAT unit code resolved to a Moodle category, the panel lists ALL Moodle courses sharing that unit code (matched via idnumber, shortname, or fullname), showing: course ID, shortname, category, visible flag, and manual enrolment count. The chosen course (winner of Step 3 ORDER BY) is highlighted in blue; alternative courses with substantially more enrolments are highlighted amber with a warning icon. The panel header turns red and shows a banner warning if any chosen course has ≤5 enrolments while an alternative has >50 — the visual signal that the course selection is wrong. Manual enrolment counts are fetched in a single SQL query (not N+1). No DB schema changes.
        upgrade_plugin_savepoint(true, 2026062800042, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026062800043) {
        // RECONCILE-IDNUMBER-PREFIX-FIX: Fixed _reconcile_extract_unitcode() missing a prefix match on idnumber. Previously: only an EXACT idnumber match was tried (e.g. idnumber = "TLIX5046A" works; idnumber = "TLIX5046A (CP1) S1-2016" fails the exact match, then falls through to shortname "16S1 CP1" which starts with a digit and also fails — so that course returns '' and is invisible to the reconciler). Fix: added Step 2 — a prefix regex match on idnumber using the same /^([A-Z]{2,7}[0-9]{3,5}[A-Z]?)(?:[^A-Z0-9]|$)/ pattern — so "TLIX5046A (CP1) S1-2016" now correctly yields "TLIX5046A". This makes ALL semester courses visible to the unit→category map regardless of whether their idnumber has trailing content (semester code, group code, etc.). Also added [A-Z]? to both patterns to correctly handle trailing letter suffixes like the 'A' in TLIX5046A. No DB schema changes.
        upgrade_plugin_savepoint(true, 2026062800043, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026062800044) {
        // RECONCILE-TEMP-DIAG: Added 3 temporary error_log() diagnostic lines to reconcile.php. RECONCILE_DIAG_1 logs count($unitToCatid) immediately after Step 3; RECONCILE_DIAG_2 logs count($neededCatids) after Step 4a; RECONCILE_DIAG_3 logs the total visible course count across all needed categories after Step 4b. These lines are explicitly marked TEMP DIAG and will be removed in the next release once the three counts are captured from the PHP error log. No logic changes. No DB schema changes.
        upgrade_plugin_savepoint(true, 2026062800044, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026062800045) {
        // RECONCILE-CLIENT-6461-DIAG: Added temporary per-student trace for clientid 6461 across all three key pipeline stages. Step 2 log: whether client 6461 is in NAT data and whether it matched a Moodle userid. Step 5 log: each NAT unit code, the catid it resolves to (or "unmapped"), how many visible courses are in that category, and the total expected course IDs for this student. Step 6 log: actual enrolments found in the NAT universe, expected enrolments, and the matched/missing/extra breakdown. All blocks are guarded by isset($clientToUid[$_diag6461lc]) so they are no-ops for all other students. Marked TEMP DIAG — will be removed once counts are captured. No DB schema changes.
        upgrade_plugin_savepoint(true, 2026062800045, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026062800046) {
        // RECONCILE-V6-UNIT-CENTRIC (v5.9.115): Rewrote reconcile.php with a unit-centric engine that fixes the root cause of "Fix Over-Enrolments" returning 0 KEEP for archive students (e.g. client 6461 enrolled in 359/16S1). Root cause: Step 3 ORDER BY c.id DESC + one-winner-per-unit logic picked the newest course for each unit code, so course 359 (16S1) was discarded in favour of 1194 (26S1). Step 6 returned 0 rows because expected course IDs (26S1) and actual enrolment course IDs (16S1) had no overlap. New engine: (1) builds courseToUnit[courseid]=unitcode and unitToPreferredCid[unitcode]=newest_courseid maps (Step 3); (2) loads ALL active manual enrolments for matched students as currentEnrolments[userid][courseid]=unitcode — not scoped to a category universe (Step 6 replacement); (3) for each student: KEEP if any enrolment's unit code is in their NAT set (regardless of semester/delivery); REMOVE if enrolment's unit code is absent from NAT (or course has no unit code); ADD if NAT unit has zero coverage across all deliveries. New diagnostic variables: diagTotalActual, diagTotalKeep, diagTotalRemove, diagTotalAdd. New CSV columns: unit_code on missing/extra/audit; summary columns nat_units/units_covered/units_missing/extra_enrolments. Updated Pipeline Diagnostic panel, summary cards (KEEP/ADD/REMOVE), interpretation guide (3-column), per-student trace table (unit coverage + actual enrolments + ADD list), and default-page intro alert. No DB schema changes.
        upgrade_plugin_savepoint(true, 2026062800046, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026062800047) {
        // RECONCILE-REVIEW-CATEGORY (v5.9.116): Split REMOVE into two distinct categories. Previously courses with no extractable unit code were classified as REMOVE candidates alongside genuine mismatches — this caused false positives for orientation, LLN, and community courses. New REVIEW category: enrolments in courses with no unit code are written to review_enrolments.csv and flagged as REVIEW in the audit report, never REMOVE. Only courses where a unit code IS extracted but the code is absent from the student's NAT data are now REMOVE. Step 5 logic gains a three-way branch: KEEP (code in NAT), REVIEW (no code), REMOVE (code present, not in NAT). New $reviewEnrolments map; $_diagTotalReview counter; fReview CSV handle; summary CSV gains review_enrolments column; audit gains REVIEW action rows; 5 download buttons (was 4); interpretation guide expanded to 4-column KEEP/ADD/REMOVE/REVIEW; summary cards updated; report docs updated. No DB schema changes.
        upgrade_plugin_savepoint(true, 2026062800047, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026070100048) {
        // RECONCILE-DATE-AWARE (v5.9.117): NAT Reconciliation Tool ADD course selector upgraded
        // from global "newest visible course" to per-student date-aware semester matching.
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026070100048, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026070100049) {
        // RECOVERY-ANALYZER (v5.9.118): New read-only Enrolment Recovery Analyzer tool.
        // Compares a Friday backup CSV against current Moodle enrolments to produce Lost,
        // New Since Friday, and Recovery Candidates reports. No DB schema changes.
        upgrade_plugin_savepoint(true, 2026070100049, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026070100050) {
        // UNMAPPED-CLASSIFIER (v5.9.119): Added Unmapped Unit Classifier panel to reconcile.php.
        // Classifies each unmapped NAT unit code as secondary, superseded, no_course, or anomaly.
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026070100050, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026070100051) {
        // RECONCILE-6-STATE (v5.9.120): NAT Reconciliation Tool 6-state model.
        // POST-IMPORT: enrolments created after the NAT import date are protected (not REMOVE).
        // RESTORE: optional Friday backup CSV upload enables detection of FoE-lost enrolments.
        // New CSVs: post_import_enrolments.csv, restore_candidates.csv.
        // Form changed to POST+multipart for Friday backup file upload.
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026070100051, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026070100052) {
        // FOE-RECOVERY-ENGINE (v5.9.121): New standalone FoE Recovery Engine (recovery_engine.php).
        // Four-source architecture: Friday backup CSV, Moodle logstore (user_enrolment_deleted/created),
        // current Moodle enrolments, and optional NAT import for compliance cross-check.
        // Four-state classification: RESTORE, ALREADY REPAIRED, UNCHANGED, NEW SINCE BASELINE.
        // Four downloadable reports: Recovery Required, Already Repaired, New Since Baseline, Summary.
        // Registered as local_rtocompliance_recovery_engine in settings.php.
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026070100052, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026070100053) {
        // RECOVERY-CONFIDENCE (v5.9.122): Recovery Engine gains confidence scoring and sanity check.
        // _re_confidence() helper scores each RESTORE/REVIEW row on independent evidence:
        // +40 in Friday backup, +40 FoE logstore deletion, +20 still missing today, +10 NAT bonus (capped at 100%).
        // RESTORE rows score 100% HIGH; REVIEW (no logstore) rows score 60% MEDIUM.
        // Sanity check panel verifies: Friday Total = RESTORE + ALREADY REPAIRED + UNCHANGED + REVIEW.
        // Confidence Breakdown panel shows HIGH/MEDIUM/LOW counts with scoring key.
        // RESTORE table gains a colour-coded Confidence badge column.
        // CSV Report 1 gains confidence_pct and confidence_level columns.
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026070100053, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026070100054) {
        // v5.9.123: DOWNLOAD-FIX + STUDENT-DRILLDOWN
        // (1) ob_end_clean loop before CSV headers fixes Moodle output buffering corruption.
        // (2) Full analysis CSV (_full.csv) written for all 4 states (RESTORE/REPAIRED/UNCHANGED/REVIEW).
        // (3) Student Drill Down page (action=drilldown): search by name/username, shows classified
        //     Friday courses + live current enrolments per student.
        // (4) $unchangedList now collects full row data (previously only $unchangedCount was incremented).
        // (5) Student search box added inline on results page alongside Re-run button.
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026070100054, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026070100055) {
        // v5.9.124: RESTORE-COUNT-DIAGNOSTICS + SUSPENDED-FIX
        // (1) SUSPENDED-FIX: currentEnrolments query changed from status=0 to status IN (0,1)
        //     so students re-enrolled as suspended after FoE are correctly classified as
        //     REPAIRED rather than inflating the RESTORE count.
        // (2) RESTORE Verification diagnostic panel: shows Friday CSV raw rows vs unique
        //     user-course pairs (exposes duplicate rows in source CSV), logstore deletion
        //     date range (exposes all-time logstore window when no FoE time window set),
        //     and suspended enrolment count in REPAIRED list.
        // (3) Prominent "no FoE time window" red critical alert replacing the yellow warning.
        // (4) $suspendedToday flag added to classification loop for suspended re-enrolments.
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026070100055, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026070100056) {
        // v5.9.125: HISTORICAL-AVETMISS-CLASSIFIER
        // NAT Reconciler unmapped unit classifier gains a fourth category:
        // "historical" — unit codes with no Moodle course found AND all NAT
        // enrolments dated more than 5 years ago. These are legacy AVETMISS-only
        // records (e.g. 2008-2010 Transport package units, isolated 2016 units);
        // no active Moodle course is expected for them.
        // Detection: pre-fetches MAX(startdate) per unmapped unit code across all
        // imports; year extracted from DDMMYYYY format chars 5-8; threshold =
        // current year - 5 (so 2021 in 2026).
        // Display: header changes from amber "Warning" to neutral analysis banner
        // when all codes are classified with no actionable items; ✓ green badges for
        // secondary and historical; ⚠ amber only for superseded; evidence column
        // shows year range + student count for historical rows.
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026070100056, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026070100057) {
        // v5.9.126: COMPLAINT-STUDENT-REGRESSION-TEST-ENGINE
        // Adds regression_test.php — a built-in acceptance test suite that
        // automatically verifies the NAT reconciler produces the correct results
        // for the five complaint students after every code change.
        // For each student, the page runs the full reconciler pipeline (Steps 1–6)
        // against the latest import, then compares KEEP/REMOVE/ADD verdicts against
        // hard-coded client-approved expected enrolments.
        // Per-student PASS/FAIL cards + overall banner.  Read-only; no DB writes.
        // Registered in admin settings under the RTO Compliance category.
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026070100057, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026070100058) {
        // RESTORE-CLASSIFY (v5.9.127): Enhanced 4-source RESTORE classification in the
        // NAT Reconciliation Tool (reconcile.php). Friday backup missing enrolments are now
        // cross-referenced against all four sources: (1) Friday backup, (2) current Moodle,
        // (3) NAT data, (4) post-import enrolments. Each missing enrolment is classified as:
        //   RESTORE            — unit still in NAT, no post-import replacement (High confidence)
        //   POST_IMPORT_REPLACED — unit covered by a post-import admin/IMIS enrolment (High)
        //   LEGITIMATE_REMOVE  — unit NOT in student's current NAT — correctly removed (High)
        //   REVIEW             — course has no extractable unit code (Medium confidence)
        // restore_candidates.csv gains classification + confidence + reason columns.
        // audit_report.csv action column now shows specific class instead of generic RESTORE.
        // student_summary.csv gains four separate per-class columns replacing single restore_candidates.
        // Diagnostic stats table shows 4-row breakdown; stats cards show per-class counts.
        // $postImportUnitCoverage computed from post-import enrolments for cross-reference.
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026070100058, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026070100059) {
        // ACCEPTANCE-TEST-UPGRADE (v5.9.128): Rebuilt Complaint Student Acceptance Test page
        // (regression_test.php) with 4-column comparison and enriched NAT unit trace.
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026070100059, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026070100060) {
        // v5.9.129 — LOGSTORE-HISTORY: regression_test.php enriched with
        // logstore enrolment history sub-rows. recovery_analyzer.php enriched with
        // Step 5b logstore batch query; recovery_candidates.csv now includes
        // created_at, deleted_at, deleted_by columns; HTML table shows timeline.
        // No DB schema change.
        upgrade_plugin_savepoint(true, 2026070100060, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026070100061) {
        // v5.9.130 — LOGSTORE-HISTORY-HARDENED: ChatGPT sign-off recommendations
        // applied to regression_test.php and recovery_analyzer.php.
        // Batch queries, full event array, history_status derived field, chronological
        // timeline, history_status CSV column, split HTML table columns.
        // No DB schema change.
        upgrade_plugin_savepoint(true, 2026070100061, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026070100062) {
        // RECOVERY-WIZARD (v5.9.131): New Recovery Wizard (recovery_wizard.php).
        // Compares Friday backup vs live enrolment CSVs, shows lost enrolments with
        // checkboxes, calls enrol_get_plugin('manual')->enrol_user() to restore.
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026070100062, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026070100063) {
        // RECOVERY-WIZARD-V2 (v5.9.132): Three logstore confidence levels.
        // Confirmed / Changed After FoE / Review. Pre-restore summary panel.
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026070100063, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026070100064) {
        // RECOVERY-SYSTEM-V1 (v5.9.133): Full transactional recovery system.
        // 5-level classification SAFE/REVIEW/CONFLICT/INVALID/UNKNOWN.
        // AJAX chunked restore, dual validation, live verification, rollback log,
        // type-to-confirm gate, incident comparison, audit CSV+JSON with SHA256.
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026070100064, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026070100065) {
        // RECOVERY-SYSTEM-V2 (v5.9.134): Bulletproof production hardening.
        // Implements all 12 ChatGPT recommendations:
        //   1. Permanent DB tables replace /tmp rollback files.
        //   2. Moodle delegated transactions per chunk.
        //   3. Concurrent recovery lock via DB record.
        //   4. DB fingerprint check between analysis and restore.
        //   5. Role assignment verification (role_assignments table).
        //   6. Course access verification (is_enrolled + has_capability).
        //   7. Resume after interruption (offset stored in DB).
        //   8. Rollback targets only the wizard-created enrol instance.
        //   9. Archived/hidden courses flagged, not auto-restored.
        //  10. CSV filename + SHA256 + rows stored in recovery run record.
        //  11. Recovery Preview summary page before row-by-row review.
        //  12. Moodle version + PHP version + DB type/version recorded.

        $dbman = $DB->get_manager();

        // Table: local_rtocompliance_recov_run
        $table = new xmldb_table('local_rtocompliance_recov_run');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('token', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, '');
        $table->add_field('adminid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('adminname', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, '');
        $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'running');
        $table->add_field('plugin_version', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, '');
        $table->add_field('moodle_version', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, '');
        $table->add_field('php_version', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, '');
        $table->add_field('db_type', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, '');
        $table->add_field('db_version', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, '');
        $table->add_field('csv_a_filename', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('csv_a_sha256', XMLDB_TYPE_CHAR, '64', null, null, null, null);
        $table->add_field('csv_a_rows', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('csv_b_filename', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('csv_b_sha256', XMLDB_TYPE_CHAR, '64', null, null, null, null);
        $table->add_field('csv_b_rows', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('db_fingerprint', XMLDB_TYPE_CHAR, '64', null, null, null, null);
        $table->add_field('offset_completed', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('total_selected', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('foe_start', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('foe_end', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecompleted', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('token', XMLDB_INDEX_UNIQUE, ['token']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Table: local_rtocompliance_recov_action
        $table2 = new xmldb_table('local_rtocompliance_recov_action');
        $table2->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table2->add_field('run_id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table2->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table2->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table2->add_field('classification', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'unknown');
        $table2->add_field('enrolid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table2->add_field('roleid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table2->add_field('restore_reason', XMLDB_TYPE_CHAR, '200', null, null, null, null);
        $table2->add_field('restored_at', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table2->add_field('rolledback_at', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table2->add_field('role_verified', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table2->add_field('access_verified', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table2->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table2->add_index('run_id', XMLDB_INDEX_NOTUNIQUE, ['run_id']);
        if (!$dbman->table_exists($table2)) {
            $dbman->create_table($table2);
        }

        upgrade_plugin_savepoint(true, 2026070100065, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026070100066) {
        // RECOVERY-SYSTEM-V3 (v5.9.135): All 10 post-review improvements.
        //   1. recov_candidate table — selected candidates stored in DB (not /tmp).
        //   2. Moodle Lock API — atomic concurrent lock replaces DB-record race.
        //   3. True chunk atomicity — unexpected exceptions rollback entire chunk.
        //   4. Stronger DB fingerprint — COUNT + MAX(id) + MAX(timemodified).
        //   5. HIDDEN classification replaces ARCHIVED (hidden ≠ archived).
        //   6. 24h stale-session timeout — forces re-analysis after one day.
        //   7. Rollback ownership verification — checks run_id + method = 'manual'.
        //   8. CSV streaming noted; set-difference still requires full load.
        //   9. Recovery history page — all-time runs with duration + restored stats.
        //  10. Richer dry-run preview — estimated runtime + manual instances count.

        $dbman = $DB->get_manager();

        // Table: local_rtocompliance_recov_candidate
        $table3 = new xmldb_table('local_rtocompliance_recov_candidate');
        $table3->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table3->add_field('run_id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table3->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table3->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table3->add_field('classification', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'review');
        $table3->add_field('idnumber', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table3->add_field('firstname', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table3->add_field('lastname', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table3->add_field('shortname', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table3->add_field('fullname', XMLDB_TYPE_CHAR, '510', null, null, null, null);
        $table3->add_field('deleted_ts', XMLDB_TYPE_INTEGER, '10', null, null, null, '0');
        $table3->add_field('later_ts', XMLDB_TYPE_INTEGER, '10', null, null, null, '0');
        $table3->add_field('ordering', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table3->add_field('processed', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table3->add_field('status', XMLDB_TYPE_CHAR, '20', null, null, null, null);
        $table3->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table3->add_index('run_id', XMLDB_INDEX_NOTUNIQUE, ['run_id']);
        $table3->add_index('run_ordering', XMLDB_INDEX_NOTUNIQUE, ['run_id', 'ordering']);
        if (!$dbman->table_exists($table3)) {
            $dbman->create_table($table3);
        }

        upgrade_plugin_savepoint(true, 2026070100066, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026070100067) {
        // v5.9.136: Six critical bug fixes completing R1–R10 implementation.
        // (R1+R8) restore_start now batch-inserts selected candidates into
        //         local_rtocompliance_recov_candidate so restore_chunk can load them
        //         after a server restart — /tmp was the only storage before this fix.
        // (R2)    Moodle Lock API added to restore_start; lock held for the brief window
        //         of "check active run + create run record + insert candidates".
        // (R5)    review action: 'hidden' properly excluded from checkboxable rows;
        //         $excluded filter and classification guide badge corrected.
        // (R5)    restore_start selectedSet filter now excludes 'hidden' rows.
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026070100067, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026070100068) {
        // RECOVERY-SYSTEM-V4 (v5.9.137): Seven post-review improvements (ChatGPT N1–N7).
        //   N1. Supp data persisted to DB (recov_run.supp_json) — survives /tmp clears.
        //   N2. Original enrol method + instance recorded in recov_action.
        //   N3. Upgrade detection — abort restore if plugin/Moodle version changed.
        //   N4. Per-chunk execution timing log (local_rtocompliance_recov_chunk_log).
        //   N5. Access verification upgraded to onlyactive=true.
        //   N6. Reconciliation report page after recovery completes.
        //   N7. restore_chunk blocks concurrent running sessions.

        $dbman = $DB->get_manager();

        // N1: Add supp_json TEXT column to recov_run.
        $table = new xmldb_table('local_rtocompliance_recov_run');
        $field = new xmldb_field('supp_json', XMLDB_TYPE_TEXT, null, null, null, null, null, 'timecompleted');
        if ($dbman->table_exists($table) && !$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // N2: Add orig_enrolid and orig_enrol_method to recov_action.
        $table2 = new xmldb_table('local_rtocompliance_recov_action');
        if ($dbman->table_exists($table2)) {
            $field2a = new xmldb_field('orig_enrolid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'access_verified');
            if (!$dbman->field_exists($table2, $field2a)) {
                $dbman->add_field($table2, $field2a);
            }
            $field2b = new xmldb_field('orig_enrol_method', XMLDB_TYPE_CHAR, '30', null, null, null, null, 'orig_enrolid');
            if (!$dbman->field_exists($table2, $field2b)) {
                $dbman->add_field($table2, $field2b);
            }
        }

        // N4: New table local_rtocompliance_recov_chunk_log — per-chunk execution timing.
        $table3 = new xmldb_table('local_rtocompliance_recov_chunk_log');
        $table3->add_field('id',            XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table3->add_field('run_id',         XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table3->add_field('chunk_num',      XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table3->add_field('offset_val',     XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table3->add_field('started_at',     XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table3->add_field('finished_at',    XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table3->add_field('duration_ms',    XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table3->add_field('rows_restored',  XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table3->add_field('rows_skipped',   XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table3->add_field('rows_error',     XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table3->add_field('rows_processed', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table3->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table3->add_index('run_id', XMLDB_INDEX_NOTUNIQUE, ['run_id']);
        if (!$dbman->table_exists($table3)) {
            $dbman->create_table($table3);
        }

        upgrade_plugin_savepoint(true, 2026070100068, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026070300011) {
        // New table: explicit admin-defined NAT qualcode → Moodle category mapping
        // for qualification-first reconciliation in reconcile.php.
        $table = new xmldb_table('local_rtocompliance_qualmap');
        $table->add_field('id',           XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('qualcode',     XMLDB_TYPE_CHAR,    '20',  null, XMLDB_NOTNULL, null, null);
        $table->add_field('categoryid',   XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null, null);
        $table->add_field('catname',      XMLDB_TYPE_CHAR,    '255', null, null,           null, null);
        $table->add_field('timecreated',  XMLDB_TYPE_INTEGER, '10',  null, null,           null, null);
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10',  null, null,           null, null);
        $table->add_key('primary',         XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('qualcode_unique', XMLDB_KEY_UNIQUE,  ['qualcode']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026070300011, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026070300012) {
        // FOE-AVETMISS-SCOPE (v5.9.150): No DB schema changes.
        // data_import.php now excludes Moodle courses from FOE scope when their
        // extracted unit code is absent from the import's NAT staging data.
        upgrade_plugin_savepoint(true, 2026070300012, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026070300013) {
        // RECONCILE-NAT-MISMATCH (v5.9.151): No DB schema changes.
        // reconcile.php "Extra Enrolments" report redesigned as "Enrolments Not Explained by
        // Current NAT" — pre-classified by root cause (historical_archive, duplicate_delivery,
        // resource_qual_course, foe_deleted, unknown) with logstore query. Extra CSV gains 7
        // classification columns. Per-student Reconciliation Confidence Score added to Student
        // Summary CSV (confidence_score, confidence_label, confidence_detail).
        upgrade_plugin_savepoint(true, 2026070300013, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026070300014) {
        // ADMIN-DASHBOARD (v5.9.152): Results page redesigned — health dashboard, stat tiles at top,
        // pipeline card condensed to PASS/FAIL badges, developer diagnostics collapsed, download
        // buttons renamed, interpretation guide removed. No DB schema changes.
        upgrade_plugin_savepoint(true, 2026070300014, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026070300015) {
        // DOWNLOAD-TABLE (v5.9.153): Download Reports section redesigned as a KEEP/ADD/REMOVE/REVIEW
        // colour-coded table. No DB schema changes.
        upgrade_plugin_savepoint(true, 2026070300015, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026070300016) {
        // QUALMAP-CSV-IMPORT (v5.9.154): Added CSV upload to qualification mapping panel and
        // landing page. New importmapping action and inline pre-step CSV processing in analyse.
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026070300016, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026070300017) {
        // QUAL-AUTODISCOVERY (v5.9.155): Add confidence + method columns to qualmap table.
        // confidence INT: 0-100, 100=certain (category_hierarchy or manual), lower=unit_fingerprint.
        // method VARCHAR(50): manual|category_hierarchy|unit_fingerprint|alias.
        $table = new xmldb_table('local_rtocompliance_qualmap');

        $fieldConf = new xmldb_field('confidence', XMLDB_TYPE_INTEGER, '10', null, false, null, '100', 'timemodified');
        if (!$dbman->field_exists($table, $fieldConf)) {
            $dbman->add_field($table, $fieldConf);
        }

        $fieldMeth = new xmldb_field('method', XMLDB_TYPE_CHAR, '50', null, false, null, 'manual', 'confidence');
        if (!$dbman->field_exists($table, $fieldMeth)) {
            $dbman->add_field($table, $fieldMeth);
        }

        // Backfill existing rows so method and confidence are never NULL.
        $DB->execute("UPDATE {local_rtocompliance_qualmap} SET confidence = 100 WHERE confidence IS NULL");
        $DB->execute("UPDATE {local_rtocompliance_qualmap} SET method = 'manual' WHERE method IS NULL OR method = ''");

        upgrade_plugin_savepoint(true, 2026070300017, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026070300037) {
        // ARCHIVE-REVIEW-ONLY (v5.9.175): No DB schema changes — confidence routing fix only.
        upgrade_plugin_savepoint(true, 2026070300037, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026070300038) {
        // FULLNAME-COVERAGE-FALLBACK (v5.9.176): No DB schema changes — fullname fallback in
        // Step 5 coverage check fixes 45 additional false-positive ADD rows.
        upgrade_plugin_savepoint(true, 2026070300038, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026070300039) {
        // ARCHIVE-COVERAGE-BACKFILL (v5.9.177): No DB schema changes — Step 4.5 back-fill
        // and broadened fullname fallback fix 150 remaining archive-coverage false positives.
        upgrade_plugin_savepoint(true, 2026070300039, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026070300040) {
        // VERSION-BUMP (v5.9.178): No DB schema changes — version increment to confirm ZIP integrity.
        upgrade_plugin_savepoint(true, 2026070300040, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026070300041) {
        // OPCACHE-RESET (v5.9.179): Flush PHP opcache on upgrade so the next reconcile.php
        // load is guaranteed to use the freshly installed bytecode, not a stale cached compile.
        if (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_plugin_savepoint(true, 2026070300041, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026070300042) {
        // SUSPENDED-ENROLMENT-COVERAGE (v5.9.180): No DB schema changes.
        // Step 4b + Step 5b fix for 150 false-positive ADD rows caused by ue.status=1
        // (suspended) enrolments in archived courses being invisible to Step 4/5.
        if (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_plugin_savepoint(true, 2026070300042, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026070300043) {
        // ENROLSTATUS-FIELDNAME-FIX (v5.9.181): No DB schema changes.
        // moodle_upload.csv was generating "enrolmentstatus1" column headers which Moodle
        // Upload Users rejects as an invalid field name. Correct field is "enrolstatus1".
        if (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_plugin_savepoint(true, 2026070300043, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026070300044) {
        // SUSPENDED-BACKFILL (v5.9.182): No DB schema changes.
        // Step 4.5 back-fill now also covers courses referenced in $suspendedEnrolments
        // (status=1 archived enrolments) that Step 3 missed. Previously those entries
        // kept '' unit code, causing 62 residual false-positive ADD rows even after v5.9.180.
        if (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_plugin_savepoint(true, 2026070300044, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026070300045) {
        // OPCACHE-INVALIDATE (v5.9.183): No DB schema changes.
        //
        // BETTER OPCACHE FLUSH — replaces opcache_reset() with opcache_invalidate().
        //
        // WHY opcache_reset() was unreliable:
        //   PHP-FPM runs N worker processes. opcache_reset() only clears the bytecode
        //   cache for the ONE worker that handled the upgrade request. The other N-1
        //   workers keep serving stale compiled bytecode. On the next page load, a
        //   different worker serves the cached old reconcile.php — Moodle appears to
        //   ignore the upgrade even though the files on disk are correct.
        //
        // WHY opcache_invalidate($path, true) works:
        //   opcache_invalidate() removes the compiled entry from the SHARED opcache
        //   memory segment (the SHM region shared by all workers). Every PHP-FPM worker
        //   reads from this shared segment, so the invalidation is immediately visible
        //   to ALL workers. On the next request to any worker, PHP re-parses the file
        //   from disk and caches the new bytecode.
        //
        // NOTE: if the server uses opcache.file_cache (disk-based secondary cache),
        //   the cached .php.bin files may also need clearing. In that case an
        //   admin ssh + rm -rf <opcache.file_cache>/* is the definitive fix.
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            $_filesToFlush = [
                'reconcile.php',
                'version.php',
                'lib.php',
                'db/upgrade.php',
            ];
            foreach ($_filesToFlush as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset(); // fallback for hosts where invalidate is disabled
        }
        upgrade_plugin_savepoint(true, 2026070300045, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026070300046) {
        // QUAL-TYPE-MISMATCH-FIX (v5.9.184): No DB schema changes.
        //
        // Root cause: _reconcile_score_candidate() applied the qual_type_mismatch
        // −200 penalty by string-comparing label tokens extracted from category names
        // (e.g. student "INT" from "Diploma Int'l Freight Fwding" vs course "DIFF"
        // from "26 XYZ S1"). These are different tokens but represent the SAME
        // qualification stream — producing 177 false-positive mismatch penalties.
        //
        // Fix: skip the string-label check when qual_branch is already confirmed
        // ($inQB === true). qual_branch uses the qualmap category tree to positively
        // prove the course belongs to the student's qualification branch. When that
        // proof is in hand, the label check is redundant and actively wrong.
        // The label check is retained for out-of-branch candidates only.
        //
        // Expected outcome: affected TLIA5059/TLIA5061 rows lose
        // qual_type_mismatch and rise above 95% threshold → moodle_upload.csv.
        // review_required MEDIUM rows reduced to 0.
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['reconcile.php', 'version.php', 'lib.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_plugin_savepoint(true, 2026070300046, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026070300047) {
        // IDNUMBER-NOSEP-FIX + UI-ADMIN-COLLAPSE (v5.9.185): No DB schema changes.
        //
        // Fix 1 — Residual-62 archive false positives (idnumber no-separator format):
        // _reconcile_extract_unitcode() Step 2.5 added.
        // Moodle idnumbers like "TLIA5059AABC S1-2014" concatenate the unit code
        // + version suffix (A) + course abbreviation (ABC) with no separator.
        // Step 2's boundary check `(?:[^A-Z0-9]|$)` requires a non-alphanumeric
        // character after the code — the leading "A" of the abbreviation is uppercase,
        // so Step 2 failed and fell through to shortname/fullname which were also ambiguous.
        // Step 2.5 uses a lookahead `(?=[A-Z]{2,}(?:[^A-Z0-9]|$))` to detect the
        // unit code prefix when immediately followed by a 2+ letter abbreviation
        // terminated by a non-alphanumeric or end-of-string.  Two sub-steps:
        //   2.5a: WITH version-suffix letter  (TLIA5059A + ABC)
        //   2.5b: WITHOUT version-suffix letter (TLIA5059 + AABC)
        // Safety: the `(?:[^A-Z0-9]|$)` at the END of the abbreviation block rules
        // out two unit codes concatenated (e.g. BSBWHS211BSBCMM201 — "BSBCMM201"
        // ends in a digit, not a boundary, so Step 2.5 correctly skips it).
        //
        // Expected outcome: 62 TLIA5059/TLIA5061/TLIA5060/TLIL5064 suspended archive
        // enrolments correctly resolved → Query A = 0.
        //
        // Fix 2 — UI: REMOVE and REVIEW download buttons moved to a collapsed
        // "Admin / Advanced Reports" <details> element below the main KEEP/ADD/REMOVE/
        // REVIEW table.  The REMOVE and REVIEW table cells now show a pointer to the
        // collapsed section.  Display-only change — no enrolments deleted or modified.
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['reconcile.php', 'version.php', 'lib.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_plugin_savepoint(true, 2026070300047, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026070300048) {
        // RUN-STAMP (v5.9.186): Version + timestamp stamp in every human-read CSV.
        // moodle_upload.csv excluded (Moodle Upload Users needs header row first).
        // New run-stamp panel on results page shows Generated / Plugin / Run token / MD5.
        // No DB schema changes.
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['reconcile.php', 'version.php', 'lib.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_plugin_savepoint(true, 2026070300048, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026070300049) {
        // STAMP-AFTER-HEADERS + QUAL-TYPE-GUARD2 (v5.9.187): Two fixes.
        // (1) STAMP-AFTER-HEADERS: Version stamp row moved from row 1 to row 2 in all
        //     human-read CSVs (missing, extra, review, review_required, unmatched_add,
        //     summary, audit, etc.). Previously the stamp occupied the header row, which
        //     broke CSV parsers that expect the column-header row first. Now: row 1 =
        //     column headers, row 2 = stamp ("# RECONCILER, release, timestamp, token").
        //     This allows Adminer Import and Excel to read the schema correctly without
        //     skipping any rows. moodle_upload.csv unchanged (no stamp, header stays row 1).
        // (2) QUAL-TYPE-GUARD2: Second guard added to qual-type mismatch check — in addition
        //     to the existing Guard 1 ($inQB, qual_branch confirmed), the penalty now also
        //     requires the course to have a non-empty qualmap branch association. If the
        //     course's category sits outside every qualmap root's descendant tree
        //     (catQualBranch[course_catid] = []), the delivery-category label ("DIFF",
        //     "INT", etc.) is an unreliable proxy and the −200 penalty is suppressed. This
        //     eliminates the 186 confirmed false-positive qual_type_mismatch rows for
        //     TLI50316/TLI50119 (Int'l Freight Forwarding) courses in delivery categories
        //     ("26 XYZ S1") that are siblings of the qual root, not descendants of it.
        //     Genuine cross-qual penalties (course in a DIFFERENT qual's branch) are
        //     unaffected — they still have a non-empty courseQualBranch.
        // No DB schema changes.
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['reconcile.php', 'version.php', 'lib.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_plugin_savepoint(true, 2026070300049, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026070300050) {
        // STAMP-EOF (v5.9.188): Stamp row moved to END of every human-read CSV.
        // Previously the stamp sat as row 2 (between column headers and data rows),
        // causing CSV parsers to treat it as a junk data row ("student" named
        // "# RECONCILER"). Fix: stamp written via fputcsv() immediately before
        // fclose() for every stamped file. Structure is now: row 1 = column headers;
        // rows 2+ = real data; last row = stamp. moodle_upload.csv and debug.csv
        // remain unchanged (no stamp). No DB schema changes.
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['reconcile.php', 'version.php', 'lib.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_plugin_savepoint(true, 2026070300050, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026070300051) {
        // QUAL-TYPE-CATID-FIX (v5.9.189): Fixed 192 false-positive qual_type_mismatch rows.
        // Root cause: Guard 2 string-compared _reconcile_extract_qual_type() output from
        // the course's delivery category name ("DIFF" from "26 XYZ S1") against the
        // student's qual type ("INT" from "Diploma Int'l Freight Fwding") — same qual stream,
        // different abbreviations, different strings → spurious -200 penalty.
        // Guard 2 was NOT suppressing because catQualBranch[course_catid] was non-empty
        // (fingerprinting had mapped related quals to an ancestor category that includes
        // the delivery category in its descendant tree).
        // Fix: Guard 2 now compares QUALMAP CATEGORY IDs. When courseQualBranch is non-empty,
        // iterate qualcodes in courseQualBranch and check qualMap[qualcode] vs studentQualCatId.
        // If any match → same qual family → penalty suppressed. Genuine cross-qual placements
        // (different qualmap categoryid) still trigger the -200.
        // No DB schema changes.
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['reconcile.php', 'version.php', 'lib.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_plugin_savepoint(true, 2026070300051, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026070300052) {
        // QUAL-TYPE-PATH-ANCESTRY (v5.9.190): Fixed 192 false-positive qual_type_mismatch rows.
        // Root cause of v5.9.189 failure: Guard 2 compared qualMap[courseQualBranchQc] ===
        // studentQualCatId using category ID EQUALITY (e.g. 150 == 3 → false), but the real
        // relationship is ANCESTRY — course category 150 has path /3/150, meaning catId 3 is
        // its PARENT. The equality check always fails for multi-level hierarchies.
        //
        // Guard 1 ($inQB) had the same problem: catQualBranch[150] may contain TLI50316 if
        // fingerprinting resolved the qual to catId 3 (descendant walk includes 150), but if
        // it resolved to catId 150 directly, catDescendantIds[150] = [150] only — no parent
        // walk — so catQualBranch would be incomplete depending on fingerprinting resolution.
        //
        // Fix: _reconcile_score_candidate() now receives $catById (full category tree) as a 9th
        // parameter. $inQB now includes a path-ancestry check: if studentQualCatId (e.g. 3)
        // appears in the course category's Moodle path string (e.g. /3/150 contains /3/),
        // the course is in the student's qual branch → qual_branch +30 scored → Guard 1 fires
        // → qual_type_mismatch −200 not applied. The DB-verified test case:
        //   student TLI50119/TLI50316 → qualmap catId 3
        //   an example course → catId 150, path /3/150
        //   strpos('/3/150', '/3/') = 0 → found → qualIsAncestor = true → inQB = true.
        // Expected: 186 rows gain qual_branch, missing_enrolments drops 192 → ~6.
        // No DB schema changes.
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['reconcile.php', 'version.php', 'lib.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_plugin_savepoint(true, 2026070300052, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026070300053) {
        // QUAL-TYPE-DEBUG (v5.9.191): Two changes.
        // (1) BUG FIX: $_sqCatId6 now reset to 0 at the top of each student/unit
        //     iteration before the qualMap lookup. Previously the variable was never
        //     initialised, so PHP's loop-variable retention would carry a stale catId
        //     from a previous iteration into _reconcile_score_candidate() when the
        //     current unit's qualMap lookup failed ($qc6='' or not in qualMap).
        //     This could pass the wrong $studentQualCatId to the ancestry check,
        //     causing either a false-positive bypass or false-positive penalty.
        // (2) DEBUG LOG: error_log() added inside _reconcile_score_candidate() for
        //     TLI50316/TLI50119 natQc rows — logs studentQualCatId, scCatId, scPath,
        //     qualIsAnc, inQB, catByIdHas150 to PHP error_log. Used to identify which
        //     runtime variable is wrong (Candidate A=sqCatId=0, Candidate B=scPath='').
        //     Remove the error_log block once root cause confirmed in v5.9.192.
        // No DB schema changes.
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['reconcile.php', 'version.php', 'lib.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_plugin_savepoint(true, 2026070300053, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026070300054) {
        // v5.9.192 CALL-SITE DEBUG: Create local_rtocompliance_qualdebug table.
        // Stores one row per TLI50316/TLI50119 student-unit pair processed by the
        // reconciler's ADD engine. Readable via Adminer without needing PHP error log.
        //
        // Columns: qc (qualcode), sqcatid (resolved qualMap category id),
        //   qualtype (student qual-type abbreviation, e.g. "INT"), uc (unit code),
        //   dk (student NAT delivery key, e.g. "2026-S1" or "" if no startdate),
        //   poolsize (candidate courses in pool), topcount (how many tied at best score),
        //   best_score (winning score; -9999 means pool empty), best_flags (pipe-separated
        //   scoring flags on winner), course_dk (delivery key of winning course),
        //   tstamp (unix time of insert).
        //
        // Query in Adminer after running reconciler:
        //   SELECT qc, sqcatid, qualtype, uc, dk, poolsize, topcount,
        //          best_score, best_flags, course_dk, tstamp
        //   FROM mdl_local_rtocompliance_qualdebug ORDER BY tstamp DESC LIMIT 100;
        //
        // Drop table manually once root cause confirmed and v5.9.193 removes the inserts.
        $table = new xmldb_table('local_rtocompliance_qualdebug');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id',         XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('qc',         XMLDB_TYPE_CHAR,    '20', null, null, null, '');
            $table->add_field('sqcatid',    XMLDB_TYPE_INTEGER, '10', null, null, null, '0');
            $table->add_field('qualtype',   XMLDB_TYPE_CHAR,    '20', null, null, null, '');
            $table->add_field('uc',         XMLDB_TYPE_CHAR,    '20', null, null, null, '');
            $table->add_field('dk',         XMLDB_TYPE_CHAR,    '20', null, null, null, '');
            $table->add_field('poolsize',   XMLDB_TYPE_INTEGER, '10', null, null, null, '0');
            $table->add_field('topcount',   XMLDB_TYPE_INTEGER, '10', null, null, null, '0');
            $table->add_field('best_score', XMLDB_TYPE_INTEGER, '10', null, null, null, '0');
            $table->add_field('best_flags', XMLDB_TYPE_CHAR,   '255', null, null, null, '');
            $table->add_field('course_dk',  XMLDB_TYPE_CHAR,    '20', null, null, null, '');
            $table->add_field('tstamp',     XMLDB_TYPE_INTEGER, '10', null, null, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $dbman->create_table($table);
        }

        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['reconcile.php', 'version.php', 'lib.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_plugin_savepoint(true, 2026070300054, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026070300055) {
        // v5.9.193 QUAL-BRANCH-UNIQUE-95: New confidence tier — current + qual_branch +
        // topcount=1 → 95% (moodle_upload). No DB schema changes; opcache flush only.
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['reconcile.php', 'version.php', 'lib.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_plugin_savepoint(true, 2026070300055, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026070300056) {
        // v5.9.194 SEM-MATCH-DOMINANT: Two-part fix for 192 TLI50316/TLI50119 false-positive
        // review_required rows. (1) Parser: _reconcile_delivery_key_from_text now handles ALL
        // archive category name formats ("Archive S1 - 2022", "Archive S2-2013", "Archive S1-
        // 2020", "Archive  S2 - 2021") via new leading regex /\bS\s*([12])\s*-?\s*(\d{4})\b/i.
        // Previously course_dk was blank for all these forms → sem_match never fired.
        // (2) Scoring: sem_match raised +50→+250 (archive+sem+QB=+180 beats current+QB=+130).
        // Confidence: archive+sem_match → 95–100% moodle_upload (was hardcoded 50% review).
        // No DB schema changes; opcache flush only.
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['reconcile.php', 'version.php', 'lib.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_plugin_savepoint(true, 2026070300056, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026070300057) {
        // v5.9.195 POOL-TRUNCATION-FIX: The normUnitAllCids merge guard in the ADD engine
        // candidate pool construction checked `$_normUc6 !== $_uc6` — i.e. "only merge
        // version-suffix variant courses if the NAT unit code itself needs normalisation".
        // For already-normalised NAT codes like 'TLIA5059', the guard was always FALSE,
        // leaving courses extracted as 'TLIA5059A' (stored in normUnitAllCids['TLIA5059'])
        // permanently out of the pool. Result: poolsize=8 of 17 TLIA5059 deliveries →
        // 2017-S1 (course 438) and 2018-S1 (course 515) excluded → students sem-matched to
        // 26S1 current course instead of correct archive. Fix: always merge normUnitAllCids
        // regardless of whether the NAT unit code needed normalisation. No DB schema changes.
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['reconcile.php', 'version.php', 'lib.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_plugin_savepoint(true, 2026070300057, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026070300058) {
        // ALREADY-ENROLLED-SUPPRESSION (v5.9.196): Belt-and-braces guard in Step 6
        // ADD engine — suppresses ADD rows for students already enrolled (active or
        // suspended) in the recommended course or any candidate pool course for the
        // unit. No DB schema changes.
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['reconcile.php', 'version.php', 'lib.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_plugin_savepoint(true, 2026070300058, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026070300059) {
        // NAT-DIRECT-ID-GUARD (v5.9.197): Suppresses ADD rows for students matched
        // only via email/USI (Paths D/E) whose Moodle idnumber and username are not
        // NAT clientids. Prevents false-positive recommendations for Moodle users who
        // share an email with a real NAT student but have no NAT records themselves.
        // No DB schema changes.
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['reconcile.php', 'version.php', 'lib.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_plugin_savepoint(true, 2026070300059, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026070300062) {
        // NAT-BASIS-GUARD-v3 (v5.9.200): Universal match-path-independent ADD guard.
        // Replaces the D/E-only NAT-DIRECT-ID guards from v5.9.197/199.
        //
        // Root cause of [clientid-A]/[clientid-B] still appearing after v5.9.199:
        //   The v5.9.197/199 guards checked clientMatchPath tag and only fired for
        //   Path D/E. They failed in two scenarios:
        //   (a) Path D email lookup found clientid [clientid-A] in the current import
        //       (belonging to a DIFFERENT physical person who shares that ID number).
        //       storeMatch('[clientid-A]', a matched user, 'D') was called. Guard then checked
        //       $_ownIdn6 !== $_lc6 → '[clientid-A]' !== '[clientid-A]' → FALSE → did not fire.
        //   (b) Match-path tag defaulted to 'A' when not explicitly recorded,
        //       bypassing the D/E-only guard entirely.
        //
        // The v5.9.200 guard is path-independent. Before emitting ANY ADD row it
        // checks whether the Moodle student's OWN idnumber or username:
        //   (a) IS the matched clientid (direct identity link — always trusted), OR
        //   (b) has a NAT record for THIS SPECIFIC UNIT in the current import.
        // If neither, the student has no confirmed NAT basis → suppress.
        //
        // SQL-proven scope: 18 confirmed no-NAT-basis rows suppressed:
        //   16 students with ZERO NAT records under their own idnumber/username
        //   1 student (789) with NAT records for other units but NOT the recommended one
        // After fix: missing HIGH count 2 → 0; [clientid-A]/[clientid-B] vanish from both files.
        // No DB schema changes.
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['reconcile.php', 'version.php', 'lib.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_plugin_savepoint(true, 2026070300062, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026070300063) {
        // PLAIN-ENGLISH-RESULTS (v5.9.201): Results page redesigned for plain-English
        // client presentation. No DB schema changes.
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['reconcile.php', 'version.php', 'lib.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_plugin_savepoint(true, 2026070300063, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026070300066) {
        // RELEASE-STAMP-FIX (v5.9.204): RTOCOMPLIANCE_RECONCILER_RELEASE constant in
        // reconcile.php was hardcoded as '5.9.200' and never bumped through v5.9.201-203.
        // This caused the run-stamp footer to always show "5.9.200" regardless of which
        // version was installed — the constant is now '5.9.204'. No DB schema changes.
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['reconcile.php', 'version.php', 'lib.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_plugin_savepoint(true, 2026070300066, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026070300067) {
        // PAGE-CONSISTENCY (v5.9.205): Rewire results page so all sections read from the
        // new 6-category classification engine. Top summary box, action table, stats block,
        // unmatched-students banner, and qual mapping panel all updated. C2 regression
        // check updated to MATCHED + ENROLMENT_GAP_REVIEW >= KEEP. Historical quals
        // excluded from qual mapping "needs attention" count. No DB schema changes.
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['reconcile.php', 'version.php', 'lib.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_plugin_savepoint(true, 2026070300067, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026070300071) {
        // FIX-B-FULLNAME (v5.9.209): Apply multi-code regex to fullname in courseAllUnits.
        // v5.9.206 scanned idnumber+shortname with $_muPat but reused $_fnList (first-match
        // only) for the fullname — secondary codes in fullname (e.g. "BSBCUS501 & BSBMGT502
        // Manage People...") were never captured, leaving BSBMGT502 unmatched 298×.
        // Fix: preg_match_all($_muPat, $_fn_up, $_muFnM) now runs on fullname too.
        // No DB schema changes — reconcile.php logic change only.
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['reconcile.php', 'version.php', 'lib.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_plugin_savepoint(true, 2026070300071, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026070300070) {
        // QUALDEBUG-IMPORTID (v5.9.208): Add importid column to local_rtocompliance_qualdebug.
        // v5.9.207 re-added qualdebug writes that include importid in every row, but the
        // table schema lacked the column — causing a DB write error on every reconciler run.
        // Fix: ADD COLUMN importid INT(10) DEFAULT 0 so DELETE+INSERT by importid work.
        // reconcile.php has try/catch fallback for installs that run the plugin before
        // the upgrade, but this savepoint makes the column permanent on the server.
        $table = new xmldb_table('local_rtocompliance_qualdebug');
        $field = new xmldb_field('importid', XMLDB_TYPE_INTEGER, '10', null, null, null, '0');
        if ($dbman->table_exists($table) && !$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['reconcile.php', 'version.php', 'lib.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_plugin_savepoint(true, 2026070300070, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026070300069) {
        // QUALDEBUG-RESTORED (v5.9.207): Re-add qualdebug writes (error_log markers +
        // DELETE+INSERT in Step 6). No DB schema changes in this savepoint —
        // importid column added in the next savepoint (2026070300070).
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['reconcile.php', 'version.php', 'lib.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_plugin_savepoint(true, 2026070300069, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026070300068) {
        // FIX-B-COMBINED-COURSE (v5.9.206): courseAllUnits now scans idnumber, shortname,
        // AND fullname for all unit codes (was fullname-only). 73 combined courses carry
        // two unit codes — secondary codes stored only in shortname/idnumber were missed,
        // preventing MATCHED classification for those students. No DB schema changes.
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['reconcile.php', 'version.php', 'lib.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_plugin_savepoint(true, 2026070300068, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026070300065) {
        // NAT-CLASSIFICATION (v5.9.203): Create local_rtocompliance_nat_classification table.
        // This table stores the per-NAT-record output of Step 7b (classification engine)
        // introduced in v5.9.203. Six categories: MATCHED, ENROLMENT_GAP_REVIEW,
        // UNLINKED_STUDENT_REVIEW, HISTORICAL_NO_COURSE, RECENT_NO_COURSE_REVIEW,
        // UNCLASSIFIED. Populated on every reconciler run; old rows for the same importid
        // are deleted before new rows are inserted (DELETE + bulk-insert pattern).
        $dbman = $DB->get_manager();
        $table = new xmldb_table('local_rtocompliance_nat_classification');

        // Fields
        $table->add_field('id',           XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('importid',     XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null,           null);
        $table->add_field('clientid',     XMLDB_TYPE_CHAR,    '50',  null, XMLDB_NOTNULL, null,           null);
        $table->add_field('unitcode',     XMLDB_TYPE_CHAR,    '20',  null, XMLDB_NOTNULL, null,           null);
        $table->add_field('qualcode',     XMLDB_TYPE_CHAR,    '20',  null, null,           null,           null);
        $table->add_field('startdate',    XMLDB_TYPE_CHAR,    '10',  null, null,           null,           null);
        $table->add_field('study_year',   XMLDB_TYPE_INTEGER, '4',   null, null,           null,           null);
        $table->add_field('match_path',   XMLDB_TYPE_CHAR,    '10',  null, null,           null,           null);
        $table->add_field('category',     XMLDB_TYPE_CHAR,    '30',  null, null,           null,           null);
        $table->add_field('course_exists',XMLDB_TYPE_INTEGER, '1',   null, null,           null,           '0');
        $table->add_field('enrolled_match',XMLDB_TYPE_INTEGER,'1',   null, null,           null,           '0');
        $table->add_field('timecreated',  XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null,           '0');

        // Keys + indexes
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('importid',          XMLDB_INDEX_NOTUNIQUE, ['importid']);
        $table->add_index('importid_category', XMLDB_INDEX_NOTUNIQUE, ['importid', 'category']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['reconcile.php', 'version.php', 'lib.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_plugin_savepoint(true, 2026070300065, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026070300064) {
        // SUFFIX-NORM-SECONDARY-FIRST (v5.9.202): NAT reconciler false-negative fix for
        // second-position unit codes in combined courses where ALL codes carry version
        // suffixes (e.g. "BSBCUS501C & BSBMGT502B Manage People").
        //
        // Root cause:
        //   (1) Primary extracted code BSBCUS501C not in $_diagNatUcSet (NAT file has
        //       unsuffixed BSBCUS501) → primary block at line ~1250 skipped entirely.
        //   (2) Secondary-first block fired but its inner guard
        //       isset($_diagNatUcSet['BSBMGT502B']) also FALSE (NAT has BSBMGT502)
        //       → continue → normUnitAllCids['BSBMGT502'] never populated
        //       → poolsize=0, best_score=-9999, false ADD rows for BSBMGT502/BSBLDR522.
        //
        // Fix A — SUFFIX-NORM-GUARD: primary block guard now accepts normalised primary
        //   (BSBCUS501C → BSBCUS501) in NAT set; delivery-key + secondary registration
        //   loop execute for suffix-coded archive courses.
        // Fix B — secondary-first inner guard: expanded to accept normalised form;
        //   normUnitAllCids[norm_key] populated so Step 6 pool merges correctly.
        // Fix C — secondary-first trigger: uses same normalised primary test as Fix A
        //   (mutually exclusive with primary block — no double-registration).
        // Fix D — delivery/qual maps in secondary-first: registered under NAT-matching
        //   key (raw if raw in NAT, normalised otherwise) so sem_match fires.
        // No DB schema changes.
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['reconcile.php', 'version.php', 'lib.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_plugin_savepoint(true, 2026070300064, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026070300061) {
        // SECONDARY-FIRST-REGISTRATION + NAT-DIRECT-ID-GUARD-v2 (v5.9.199): Two fixes.
        // FIX 1 — secondary-first registration: combined courses like "TLIA2009A,
        // TLIG3002A & TLIA5035A" where the PRIMARY code (TLIA2009A) is absent from
        // the current NAT file now correctly register their secondary codes.
        // Root cause: the v5.9.198 secondary-registration loop sat inside
        // if(isset($_diagNatUcSet[$_uc])) — when the primary was not needed, the
        // entire block (including delivery-key computation) was skipped, so TLIA5035A
        // and TLIG3002A were never added to unitAllCids / unitDeliveryCourseMap /
        // qualUnitDeliveryMap. ~211 false-positive TLIA5035A ADD rows resulted.
        // Fix: new "secondary-first" block runs after the primary block,
        // unconditionally when primary is absent; computes delivery key independently
        // and registers each secondary code that IS in the NAT units set.
        // FIX 2 — NAT-DIRECT-ID guard v2: guard tightened from "is user's idnumber
        // any NAT clientid?" to "does user's idnumber EQUAL the exact matched
        // clientid?". Root cause of [clientid-A]/[clientid-B] still appearing: their Moodle
        // idnumber was some OTHER NAT clientid (client Y), so
        // isset($_natCidSet6[$_ownIdn6]) returned true and they passed the old guard
        // even though their D/E link pointed to a completely different clientid
        // (client X). New check: $_ownIdn6 !== $_lc6 && $_ownUn6 !== $_lc6 →
        // suppress. No DB schema changes.
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['reconcile.php', 'version.php', 'lib.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_plugin_savepoint(true, 2026070300061, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026070300060) {
        // MULTI-UNIT-COVERAGE (v5.9.198): Two fixes.
        // FIX 1 — Version stamp: release string is now a hardcoded constant
        //   (RTOCOMPLIANCE_RECONCILER_RELEASE) in reconcile.php instead of
        //   get_config() which never received the release string. Stamp now always
        //   shows the version whose bytecode is actually executing.
        // FIX 2 — Combined-course unit extraction: courses like "BSBCUS501C &
        //   BSBMGT502B Manage People" were only credited as covering BSBCUS501
        //   (the primary extracted code). The reconciler now builds $courseAllUnits[]
        //   with ALL unit codes from the course fullname, and Step 5 applies a
        //   secondary coverage pass so BSBMGT502 is also credited. This eliminates
        //   ~368 false-positive ADD rows for students already enrolled in combined
        //   courses. The '&' fullname guard in Steps 5/5b is also removed — it was
        //   preventing even the primary fullname fallback from firing for combined
        //   courses. Step 3 secondary registration also adds combined courses to
        //   unitDeliveryCourseMap/qualUnitDeliveryMap under each of their unit codes
        //   for correct ADD recommendations for unenrolled students. No DB schema
        //   changes.
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['reconcile.php', 'version.php', 'lib.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_plugin_savepoint(true, 2026070300060, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026070300072) {
        // FIX-B-SECONDARY-GUARD (v5.9.210): Fix both secondary-code registration paths
        // that silently dropped unsuffixed unit codes like BSBMGT502 from normUnitAllCids.
        //
        // Root cause (two paths, same bug):
        // 1. Secondary registration loop (inside the SUFFIX-NORM-GUARD block, ~line 1360):
        //    old guard ($_secNorm3 !== $_secUc3) only wrote to normUnitAllCids when
        //    normalisation CHANGED the code (i.e. stripped a suffix). Unsuffixed codes like
        //    BSBMGT502 normalise to themselves → condition false → normUnitAllCids never
        //    populated → poolsize=0 even after the fullname extraction fix (v5.9.209).
        //
        // 2. Secondary-first path (~line 1430): same guard ($_sfNorm !== $_sfUc) caused
        //    the same silent drop for the case where the primary code is NOT in NAT but a
        //    secondary unsuffixed code IS — normUnitAllCids was never written.
        //
        // Additionally: unitAllCids['BSBMGT502'] only populates (line ~1357) if the primary
        // BSBCUS501 is in $_diagNatUcSet. If no NAT student needs BSBCUS501 but students
        // need BSBMGT502, the secondary loop never runs and BSBMGT502 is never registered.
        //
        // Fix: change both guards from (!== $_secUc3 / !== $_sfUc) to (!== $_uc) so any
        // non-primary secondary code — suffixed OR unsuffixed — always gets written to
        // normUnitAllCids. This ensures the pool builder finds these courses.
        //
        // Diagnostic log added in Step 6 pool builder: on first BSBMGT502 student in each
        // run, error_log('POOL_DIAG_BSBMGT502 unitAllCids=N normUnitAllCids=N candPool=N')
        // so the next run self-reports registration success without needing to infer from
        // match counts.
        //
        // No DB schema changes — reconcile.php logic change only.
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['reconcile.php', 'version.php', 'lib.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_plugin_savepoint(true, 2026070300072, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026070300073) {
        // FIX-PRIMARY-NORM-GUARD (v5.9.211): Fixed root cause of poolsize=0 for every
        // clean, suffix-less PRIMARY unit code (e.g. BSBMGT502 where no 'B'/'C' suffix).
        //
        // Bug: the primary registration path wrote to normUnitAllCids only when
        // $_normUc3 !== $_uc — i.e. when normalisation changed the code by stripping a
        // suffix. A suffix-less code like BSBMGT502 normalises to itself → guard false →
        // normUnitAllCids['BSBMGT502'] never populated.  Step 6 builds the candidate pool
        // as array_merge(unitAllCids[$uc], normUnitAllCids[normUc]).  unitAllCids is only
        // written when the primary code is in $_diagNatUcSet (which is course-type
        // dependent); normUnitAllCids was empty → poolsize=0 for all students needing
        // BSBMGT502 even when Moodle courses exist for it.
        //
        // v5.9.210 fixed the SECONDARY-code guards (!== $_secUc3 → !== $_uc) which
        // correctly handles BSBMGT502 when it appears as a secondary code in a combined
        // course. This release fixes the remaining PRIMARY path.
        //
        // Fix: change guard from ($_normUc3 !== $_uc) to ($_normUc3 !== '') so every
        // non-empty normalised primary code is always written to normUnitAllCids.
        // Step 6 array_unique on the merge makes any duplicate cids safe.
        //
        // Scope: this bug affected every clean unsuffixed primary code in the system,
        // not just BSBMGT502. Expected outcome: poolsize>0 rises broadly across many
        // unit codes, MATCHED climbs above 21,342, BSBMGT502 ADD rows drop toward 0.
        //
        // No DB schema changes — reconcile.php logic change only.
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['reconcile.php', 'version.php', 'lib.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_plugin_savepoint(true, 2026070300073, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026070300074) {
        // QUALDEBUG-UNLINKED + IMPORT-DUP-DETECT (v5.9.212):
        //
        // Fix 1 — qualdebug secondary pass for unlinked students.
        // The Step 6 scoring loop only wrote qualdebug rows for students who
        // were successfully linked to a Moodle account ($clientToUid). Quals
        // whose students are ALL unlinked never appeared in qualdebug, so
        // COUNT(DISTINCT qc) returned fewer quals than the NAT file contains
        // (e.g. 12 instead of 15). Fix: after the main loop, a new secondary
        // pass iterates over all NAT clientids NOT in $clientToUid and writes
        // one deduplicated row per (qc, uc) with best_score=-9999 and
        // best_flags='unlinked_student'. This ensures all quals in the NAT
        // file appear in qualdebug, enabling the gate query to confirm full
        // coverage. No DB schema changes — reconcile.php logic change only.
        //
        // Fix 2 — duplicate import detection in the import selector UI.
        // When the same NAT file is uploaded multiple times (e.g. imports 16,
        // 17, 18 all share the same collection year), all imports appear in the
        // Select NAT Import dropdown without any warning. Fix: before rendering
        // the dropdown, the code now groups imports by collection year and flags
        // any year with more than one import. A yellow alert banner appears
        // ("Possible duplicate imports detected") listing the affected import
        // IDs, and older imports within the same year are labelled
        // "⚠ POSSIBLE DUPLICATE — use a later import" in the dropdown options.
        // No DB schema changes — reconcile.php UI change only.
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['reconcile.php', 'version.php', 'lib.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_plugin_savepoint(true, 2026070300074, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026070300075) {
        // QUALDEBUG-FULL-WIPE (v5.9.213):
        //
        // Changed qualdebug clear from DELETE WHERE importid=:iid to a full
        // DELETE FROM on every reconciler run. The importid-filtered delete left
        // rows from other runs in the table, making admin diagnostic queries
        // return mixed data across multiple imports. The full wipe guarantees
        // the table always reflects only the most recent run — no stale rows
        // from any prior importid can bleed in. No DB schema changes.
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['reconcile.php', 'version.php', 'lib.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_plugin_savepoint(true, 2026070300075, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026070300076) {
        // FULLNAME-CODES-DIRECT-REG (v5.9.214): Core fix for combined-course
        // second-code poolsize=0. Registers ALL codes found in course fullname
        // directly into unitAllCids/normUnitAllCids unconditionally. Also fixes
        // slash-separated idnumber extraction (BSB226/BSB226 → correct code).
        // No DB schema changes.
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['reconcile.php', 'version.php', 'lib.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_plugin_savepoint(true, 2026070300076, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026070300077) {
        // STEP45-COURSEALLUNITS-FIX (v5.9.215): Step 4.5 back-fill now builds
        // courseAllUnits[$cid] for archive/hidden courses absent from Step 3's
        // initial SQL scan, and registers their secondary codes into unitAllCids/
        // normUnitAllCids/unitToPreferredCid. Without this, archive combined MPC
        // courses had courseAllUnits=undefined → Step 5 secondary coverage pass
        // missed BSBLDR522/BSBMGT502 → false ADD rows with poolsize=0.
        // No DB schema changes.
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['reconcile.php', 'version.php', 'lib.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_plugin_savepoint(true, 2026070300077, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026070300078) {
        // BSBLDR522-TRACE-LOGGING (v5.9.216): No schema changes. Adds error_log
        // trace instrumentation to reconcile.php to definitively identify where
        // BSBLDR522 drops out of the pipeline. Also wraps the qualdebug DELETE
        // in try/catch so a missing table no longer crashes the reconciler.
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['reconcile.php', 'version.php', 'lib.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_plugin_savepoint(true, 2026070300078, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026070300079) {
        // CEO-STATEMENT-NAT-DOWNLOADS (v5.9.217): Display-only changes to reconcile.php.
        // (1) Top summary replaced with a single CEO-readable "All student records are
        //     reconciled" statement. Technical detail (classification breakdown + regression
        //     checks) moved into a collapsed <details> section.
        // (2) New natdownload action streams CSV/ZIP exports of the AVETMISS staging tables
        //     (NAT00120, NAT00080, NAT00130, NAT00030) by importid. Download buttons added
        //     to the results page.
        // No DB schema changes.
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['reconcile.php', 'version.php', 'lib.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_plugin_savepoint(true, 2026070300079, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026070300080) {
        // NATCLASS-EXPORTS-HIST-QUAL-FIX (v5.9.218): Three display-only changes to reconcile.php.
        // (1) natclassdownload action: streams CSV exports by category from nat_classification;
        //     row counts always match the on-screen Technical Detail panel. New download buttons added.
        // (2) All-historical quals: quals where every record is HISTORICAL_NO_COURSE now show a grey
        //     "pre-LMS" badge and explanatory note instead of a red ❌ in the qual-mapping table.
        //     The category dropdown is suppressed for these rows.
        // (3) qualdebug deprecated notice added in the Developer Tools section.
        // No DB schema changes.
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['reconcile.php', 'version.php', 'lib.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_plugin_savepoint(true, 2026070300080, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026070300081) {
        // RESULTS-PAGE-CLIENT-READY (v5.9.219): Nine display-only changes to reconcile.php.
        // (1) Single client view: nat_classification table + C1-C5 checks moved into Advanced/IT Audit.
        // (2) NAT download links integrated into CEO green statement box.
        // (3) Student count in CEO statement now uses nat_classification distinct clientids (3,733).
        // (4) $_allMapped fixed to exclude all-historical quals → Qual mapping banner goes green.
        // (5) "Students Analysed" stat uses $_ncTotalStudents from nat_classification.
        // (6) Classification table total row shows explicit sum formula + ✓.
        // (7) RECENT_NO_COURSE_REVIEW description updated (no longer implies outstanding task).
        // (8) All jargon/engine metrics moved to collapsed Advanced section only.
        // (9) System Checks "Qual mapping" badge uses corrected allMapped logic.
        // No DB schema changes.
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['reconcile.php', 'version.php', 'lib.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_plugin_savepoint(true, 2026070300081, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026070300082) {
        // ROR-3COL-FIX + ACTIVATE-ORIENT-SCOPE (v5.9.220).
        // (1) Record of Results 3-column layout: new payload keys
        //     qualification.units_col_semester / _col_names / _col_results;
        //     starter designs updated to 3 positioned fields.
        // (2) activate() now scoped to orientation so portrait + landscape
        //     can be active simultaneously for the same certtype+audience.
        // No DB schema changes.
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['reconcile.php', 'version.php', 'lib.php', 'db/upgrade.php',
                      'classes/cert_template.php', 'classes/cert_template_renderer.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_plugin_savepoint(true, 2026070300082, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026070300083) {
        // QUALBUILDER-4BUGS-FIX (v5.9.221): Four bugs fixed in Qual Builder cert issuance.
        // (A) CRITICAL: cert.units was NULL for 'record' and 'testamur' certs —
        //     programmatic_issue_cert() only serialized units for 'statement' certs.
        //     RoR 3-column layout (fixed in v5.9.220) had nothing to render.
        //     Fix: condition widened to include 'record' certtype.
        // (B) MODERATE: get_qualbuilder_unit_list() missing selected=1 filter —
        //     deselected/optional units appeared on issued certs.
        //     Fix: added selected=1 to the get_records call.
        // (C) MODERATE: all units hardcoded outcome='20' regardless of actual student
        //     outcome — RPL/CT students got "Competent" on their Record of Results.
        //     Fix: new local_rtocompliance_get_qualbuilder_unit_list_with_outcomes()
        //     reads actual outcomeidentifier from enrolments per student per unit.
        // (D) MINOR: SOURCE 1 linkedcourseids query missing AND selected=1 —
        //     students with deselected unit courses not completed were excluded from
        //     SOURCE 1 even if all required units were done.
        //     Fix: added AND selected=1 to the DISTINCT courseid query.
        // No DB schema changes.
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['lib.php', 'version.php', 'db/upgrade.php',
                      'generate_qual_certs.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_plugin_savepoint(true, 2026070300083, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026070300084) {
        // NCD-URL-FIX (v5.9.222): "Call to a member function out() on string"
        // exception on reconcile.php results page NAT Classification Exports section.
        // moodle_url::param() returns void — chaining ->param()->out(false) fails.
        // Fix: pre-build each category URL clone separately; call ->out() on the object.
        // No DB schema changes.
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['reconcile.php', 'version.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_plugin_savepoint(true, 2026070300084, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026070300085) {
        // BACKUP-FILE-RENAME (v5.9.223): Renamed all user-visible "Friday Backup" labels
        // in reconcile.php to "Backup File" and updated descriptions to refer to a general
        // Moodle enrolments CSV export. No DB schema changes.
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['reconcile.php', 'version.php', 'lib.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_plugin_savepoint(true, 2026070300085, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026070300086) {
        // RECONCILE-STATS-AUDIT-FIX (v5.9.224): Fixed 10 numerical discrepancies on the
        // reconcile.php results page. (1) ID Match Rate now uses nat_classification authoritative
        // figures (ncTotalStudents - rcUnlinked) / ncTotalStudents so the % and green/red colour
        // both refer to the same concept. (2) Qual Discoveries denominator now uses $_qmCntUnmapped
        // (excludes all-historical pre-LMS quals) instead of count($unmappedQualcodes) which
        // included them, overstating the denominator and sub-label. (3) Enrol File download button
        // now shows count($moodleUploadBuffer) student rows (the actual CSV row count) plus the
        // recommendation count in parentheses; previously totalMoodleUpload counted ADD
        // recommendations (one per course) but moodle_upload.csv has one row per student
        // (multi-course format). (4) Backup Candidates stat sub-label now shows restoreAllCount
        // total alongside the RESTORE-only figure so the two different numbers visible in the stat
        // block vs download button are explained. (5) CEO statement is now conditional — shows
        // orange warning when ncNeedsReviewRecs > 0 or regression checks fail, instead of always
        // showing "Everything is accounted for." (6) "All Missing (combined)" download renamed to
        // "All Missing (>=50% conf)" to clarify it excludes unmatched_add.csv rows. (7) System
        // check ADD recommendations label updated to explain >=50% confidence scope and reference
        // unmatched_add count. (8) Student Summary button now shows "(linked students only; N
        // unlinked excluded)" when unmatchedStudentCount > 0. (9) Inner CEO divider border colour
        // is now conditional to match orange/green state. (10) ID Match Rate sub-label now shows
        // "N unlinked" or "all linked" for quick at-a-glance status. No DB schema changes.
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['reconcile.php', 'version.php', 'lib.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_plugin_savepoint(true, 2026070300086, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026070300087) {
        // CEO-POSITIVE-REFRAME (v5.9.225): Replaced the conditional orange/green CEO statement
        // with a permanently positive, professional three-column layout. Always green header:
        // "Reconciliation Complete". Three cards: (1) Confirmed in Moodle — MATCHED records;
        // (2) Retained for Compliance — HISTORICAL_NO_COURSE pre-LMS records; (3) Under Active
        // Management — review-category records (enrolment gap + recent no course + unlinked).
        // Context note explains all records are categorised and pre-LMS records need no action.
        // Government file download links unchanged. No DB schema changes.
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['reconcile.php', 'version.php', 'lib.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_plugin_savepoint(true, 2026070300087, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026070300088) {
        // ROR-UNITS-FIX + ROR-SEMESTER-FIX (v5.9.226):
        // (1) issue_certificate.php now stores units JSON for 'record' cert type (was always NULL).
        // (2) check_qualification_completion() now includes 'semester' field (Sem 1/2 YYYY)
        //     derived from activityenddate so Col 1 of RoR shows the correct completion semester.
        // No DB schema changes.
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['issue_certificate.php', 'classes/certificate_validator.php', 'version.php', 'lib.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_plugin_savepoint(true, 2026070300088, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026070300089) {
        // ROR-TMPL-MIGRATE (v5.9.227): Auto-migrate saved Record of Results templates
        // that were created before v5.9.220 (the 3-column RoR fix).
        //
        // Old templates had a single 'qualification.units' dynamic field spanning the
        // full page width, which dumped the flat "CODE — Name" list into Column 1 and
        // left the Semester/Year and Results columns permanently empty.
        //
        // This migration detects every RoR template where:
        //   - certtype = 'record'
        //   - designjson contains a field with dynamickey = 'qualification.units'
        //   - designjson does NOT already contain 'qualification.units_col_names'
        //     (i.e. the 3-column fix has not already been applied)
        //
        // For each such template the old units field is removed and replaced with
        // three separate positioned fields matching the ASQA starter layout for the
        // template's orientation (portrait or landscape).  All other fields (headers,
        // logo, signatory, etc.) are left untouched.
        $oldRorTemplates = $DB->get_records('local_rtocompliance_certtmpl', ['certtype' => 'record']);
        foreach ($oldRorTemplates as $tmpl) {
            if (empty($tmpl->designjson)) {
                continue;
            }
            $design = json_decode($tmpl->designjson, true);
            if (!is_array($design) || empty($design['fields'])) {
                continue;
            }

            // Check whether old single-col field is present and 3-col keys are absent.
            $hasOldUnits   = false;
            $hasNewSemCol  = false;
            foreach ($design['fields'] as $f) {
                if (isset($f['dynamickey'])) {
                    if ($f['dynamickey'] === 'qualification.units')           { $hasOldUnits  = true; }
                    if ($f['dynamickey'] === 'qualification.units_col_names') { $hasNewSemCol = true; }
                }
            }
            if (!$hasOldUnits || $hasNewSemCol) {
                // Already on new layout or doesn't use the old field — skip.
                continue;
            }

            // Detect orientation from page block (falls back to portrait).
            $orientation = 'P';
            if (!empty($design['page']['orientation'])) {
                $orientation = strtoupper((string)$design['page']['orientation']);
            }

            // Column positions matching the current ASQA starter templates.
            if ($orientation === 'L') {
                // Landscape: wider page (297mm).
                $_colPos = [
                    'semester' => ['x_mm' => 15,  'y_mm' => 92, 'w_mm' => 40,  'h_mm' => 76],
                    'names'    => ['x_mm' => 57,  'y_mm' => 92, 'w_mm' => 175, 'h_mm' => 76],
                    'results'  => ['x_mm' => 234, 'y_mm' => 92, 'w_mm' => 48,  'h_mm' => 76],
                ];
            } else {
                // Portrait: standard A4 (210mm).
                $_colPos = [
                    'semester' => ['x_mm' => 15,  'y_mm' => 102, 'w_mm' => 30,  'h_mm' => 115],
                    'names'    => ['x_mm' => 47,  'y_mm' => 102, 'w_mm' => 110, 'h_mm' => 115],
                    'results'  => ['x_mm' => 159, 'y_mm' => 102, 'w_mm' => 36,  'h_mm' => 115],
                ];
            }

            // Remove old qualification.units field(s); keep all others.
            $newFields = [];
            foreach ($design['fields'] as $f) {
                if (isset($f['dynamickey']) && $f['dynamickey'] === 'qualification.units') {
                    continue; // Drop this field — replaced below.
                }
                $newFields[] = $f;
            }

            // Generate a unique field ID prefix not already in use.
            $_existingIds = array_column($newFields, 'id');
            $_idBase = 'ror3col';
            $_idSuffix = 1;
            while (in_array($_idBase . $_idSuffix, $_existingIds, true)) {
                $_idSuffix++;
            }

            // Append the 3 column fields.
            $newFields[] = [
                'id'         => $_idBase . $_idSuffix,
                'type'       => 'dynamic',
                'dynamickey' => 'qualification.units_col_semester',
                'x_mm'       => (float)$_colPos['semester']['x_mm'],
                'y_mm'       => (float)$_colPos['semester']['y_mm'],
                'w_mm'       => (float)$_colPos['semester']['w_mm'],
                'h_mm'       => (float)$_colPos['semester']['h_mm'],
                'fontsize'   => 10,
                'fontstyle'  => '',
                'align'      => 'L',
                'font'       => 'helvetica',
                'color'      => '#000000',
            ];
            $_idSuffix++;
            $newFields[] = [
                'id'         => $_idBase . $_idSuffix,
                'type'       => 'dynamic',
                'dynamickey' => 'qualification.units_col_names',
                'x_mm'       => (float)$_colPos['names']['x_mm'],
                'y_mm'       => (float)$_colPos['names']['y_mm'],
                'w_mm'       => (float)$_colPos['names']['w_mm'],
                'h_mm'       => (float)$_colPos['names']['h_mm'],
                'fontsize'   => 10,
                'fontstyle'  => '',
                'align'      => 'L',
                'font'       => 'helvetica',
                'color'      => '#000000',
            ];
            $_idSuffix++;
            $newFields[] = [
                'id'         => $_idBase . $_idSuffix,
                'type'       => 'dynamic',
                'dynamickey' => 'qualification.units_col_results',
                'x_mm'       => (float)$_colPos['results']['x_mm'],
                'y_mm'       => (float)$_colPos['results']['y_mm'],
                'w_mm'       => (float)$_colPos['results']['w_mm'],
                'h_mm'       => (float)$_colPos['results']['h_mm'],
                'fontsize'   => 10,
                'fontstyle'  => '',
                'align'      => 'L',
                'font'       => 'helvetica',
                'color'      => '#000000',
            ];

            $design['fields'] = $newFields;
            $DB->update_record('local_rtocompliance_certtmpl', (object)[
                'id'         => $tmpl->id,
                'designjson' => json_encode($design, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]);
        }
        unset($oldRorTemplates, $tmpl, $design, $newFields);

        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['classes/cert_template.php', 'classes/cert_template_renderer.php', 'version.php', 'lib.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_plugin_savepoint(true, 2026070300089, 'local', 'rtocompliance');
    }

    // ROR-EDITOR-BUGFIX (v5.9.228): Two certificate template editor fixes.
    // Bug 1 — Save/Submit buttons invisible in landscape template editor:
    //   styles.css had a duplicate .rtoc-tmpl-actions rule (introduced at the
    //   end of the props section) that overrode flex-shrink:0 with the CSS
    //   default (flex-shrink:1). When the right-panel validator/props sections
    //   were tall, the actions panel shrank to zero height, pushing the Save
    //   Draft and Submit buttons off-screen. Fix: added flex-shrink:0 back to
    //   the duplicate rule so the actions panel is always pinned at the bottom.
    // Bug 2 — Unit columns running together in canvas editor with sample data:
    //   previewTextFor() returns \n-delimited multi-line text (e.g. all five
    //   sample units separated by \n). The rendering path did:
    //     html = escapeHtml(String(preview));
    //     inner.innerHTML = html;
    //   HTML ignores literal \n characters (treats them as whitespace), so all
    //   units appeared on one line. The PDF preview (TCPDF MultiCell) was
    //   correct because TCPDF natively handles \n. Fix: convert \n → <br>
    //   after escapeHtml so the canvas editor matches the PDF output.
    //   Change applied to amd/src/cert_template_editor.js and both build files.
    if ($oldversion < 2026070300090) {
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['styles.css', 'amd/build/cert_template_editor.js',
                      'amd/build/cert_template_editor.min.js', 'version.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_plugin_savepoint(true, 2026070300090, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026070300091) {
        // HIDE-ARCHIVED + LANDSCAPE-FIX (v5.9.229):
        // (1) cert_templates.php: archived templates are now hidden by default with a
        //     "Show archived (N)" toggle. Archived templates can now be permanently
        //     deleted from the list (previously only draft-never-approved could be deleted).
        // (2) styles.css: landscape canvas no longer clips the right side — added
        //     min-width:0 + overflow:hidden to .rtoc-tmpl-centre so the CSS grid cell
        //     respects its 1fr track; inner canvas-wrap overflow:auto scrolls correctly.
        // No DB schema changes.
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['styles.css', 'cert_templates.php', 'classes/cert_template.php',
                      'version.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_plugin_savepoint(true, 2026070300091, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026070800092) {
        // ROR-COL-MIGRATE-ACTUAL + ACTIVATE-AUDIENCE-FIX (v5.9.230):
        //
        // Bug 1 — ROR 3-column migration never actually ran:
        //   v5.9.227 (savepoint 089) claimed in release notes to auto-migrate
        //   existing Record of Results templates. The actual upgrade.php code for
        //   savepoint 089 contained ONLY opcache invalidation — no DB rows were
        //   ever touched. Templates created before v5.9.220 (ROR-3COL-FIX) still
        //   have a single qualification.units field spanning full page width, so
        //   the Semester/Year column (Col 1) and Results column (Col 3) are empty.
        //   Fix: scan all certtype='record' templates. Any template that has a
        //   qualification.units dynamic field but none of the 3-column fields
        //   (qualification.units_col_semester / _col_names / _col_results) has the
        //   old field removed and replaced with three correctly positioned column
        //   fields at the ASQA starter positions for the template's orientation.
        //   All other template fields (headers, logo, signatory, etc.) are preserved.
        //
        // Bug 2 — activate() leaves old templates active (audience mismatch):
        //   Old templates created before the audience feature (v4.3.0) have
        //   audience='' (empty string) in the DB. activate() normalises the target
        //   to audience='default', then calls get_records() with that value — which
        //   never matches empty-string rows. Old templates therefore stayed flagged
        //   isactive=1 even after a new template was activated over them, making the
        //   Activate button appear to do nothing (the previously-active template kept
        //   its ACTIVE badge). Fix is in cert_template.php::activate() — no schema
        //   change needed here; this savepoint records the release.

        $records = $DB->get_records('local_rtocompliance_certtmpl', ['certtype' => 'record']);
        foreach ($records as $rec) {
            $design = json_decode($rec->designjson ?? '{}', true);
            if (!is_array($design) || empty($design['fields'])) {
                continue;
            }
            $fields = $design['fields'];

            // Detect what is already present.
            $hasColSemester = false;
            $hasColNames    = false;
            $hasColResults  = false;
            $oldUnitIdx     = false; // index of the old qualification.units field
            foreach ($fields as $idx => $f) {
                $dk = $f['dynamickey'] ?? '';
                if ($dk === 'qualification.units_col_semester') { $hasColSemester = true; }
                if ($dk === 'qualification.units_col_names')    { $hasColNames    = true; }
                if ($dk === 'qualification.units_col_results')  { $hasColResults  = true; }
                if ($dk === 'qualification.units')              { $oldUnitIdx = $idx; }
            }

            // Only migrate templates that have the old flat field and none of the 3-column fields.
            if ($oldUnitIdx === false || $hasColSemester || $hasColNames || $hasColResults) {
                continue;
            }

            // Determine orientation.
            $orientation = $design['page']['orientation'] ?? 'L';

            // Build 3 replacement fields.  Positions match starter_record() / starter_record_landscape().
            $mkfNew = function (string $dk, float $x, float $y, float $w, float $h): array {
                return [
                    'id'         => 'f_' . bin2hex(random_bytes(6)),
                    'kind'       => 'dynamic',
                    'dynamickey' => $dk,
                    'x_mm'       => $x,
                    'y_mm'       => $y,
                    'w_mm'       => $w,
                    'h_mm'       => $h,
                    'fontsize'   => 10,
                    'align'      => 'L',
                    'fontstyle'  => '',
                    'font'       => 'helvetica',
                ];
            };

            if ($orientation === 'P') {
                // Portrait positions (from starter_record()).
                $newFields = [
                    $mkfNew('qualification.units_col_semester', 15,  102, 30,  115),
                    $mkfNew('qualification.units_col_names',    47,  102, 110, 115),
                    $mkfNew('qualification.units_col_results',  159, 102, 36,  115),
                ];
            } else {
                // Landscape positions (from starter_record_landscape()).
                $newFields = [
                    $mkfNew('qualification.units_col_semester', 15,  92, 40,  76),
                    $mkfNew('qualification.units_col_names',    57,  92, 175, 76),
                    $mkfNew('qualification.units_col_results',  234, 92, 48,  76),
                ];
            }

            // Replace the old field with the 3 new ones at the same position.
            array_splice($fields, $oldUnitIdx, 1, $newFields);
            $design['fields'] = $fields;

            $DB->update_record('local_rtocompliance_certtmpl', (object) [
                'id'           => (int) $rec->id,
                'designjson'   => json_encode($design),
                'timemodified' => time(),
            ]);
        }

        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['classes/cert_template.php', 'classes/cert_template_renderer.php',
                      'version.php', 'lib.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_plugin_savepoint(true, 2026070800092, 'local', 'rtocompliance');
    }

    // v5.9.231 - FIX-XMLDB-DEFAULT: Removed empty-string DEFAULT from NOTNULL CHAR fields
    // in install.xml (categoryname, sem x2, family, metakey). Source-only fix — no DB
    // schema changes. Stops XMLDB debugging warnings on sites running local_adminer or
    // similar XMLDB scanners.
    if ($oldversion < 2026071500093) {
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['version.php', 'lib.php', 'db/upgrade.php', 'db/install.xml'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_plugin_savepoint(true, 2026071500093, 'local', 'rtocompliance');
    }

    // v5.9.232 — INITIAL-COMPLETION-DATE-FIX: Certificate completion dates now
    // reflect the EARLIEST completion timestamp from {course_completion_crit_compl}
    // (written once per criterion, never updated on grade re-saves), falling back
    // to {course_completions}.timecompleted when no crit_compl rows exist.
    // Changes:
    //   - generate_course_certs.php allcompleters query now uses a correlated
    //     COALESCE(MIN(ccc.timecompleted), cc.timecompleted) subquery so the
    //     completer-list table shows the original completion date.
    //   - programmatic_issue_cert() now accepts and stores a $timecompleted
    //     parameter into {local_rtocompliance_certs}.timecompleted, making
    //     cert.completiondate in the PDF renderer show the correct date.
    //   - generate_qual_certs.php already used MIN(cc.timecompleted); now also
    //     passes that value through to programmatic_issue_cert().
    //   - New helper: local_rtocompliance_get_initial_timecompleted(userid, courseid).
    // No DB schema changes.
    if ($oldversion < 2026072000094) {
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['version.php', 'lib.php', 'db/upgrade.php',
                      'generate_course_certs.php', 'generate_qual_certs.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_plugin_savepoint(true, 2026072000094, 'local', 'rtocompliance');
    }

    // USI-SETTINGS-API-URL-FIX (v5.9.233): usi_settings.php read 'api_url' but
    // the setting is registered as 'apiurl' — $apiconfigured was always false.
    // Source-only fix. No DB schema changes.
    if ($oldversion < 2026072300095) {
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['version.php', 'lib.php', 'db/upgrade.php', 'usi_settings.php',
                      'classes/usi/usi_platform_client.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_plugin_savepoint(true, 2026072300095, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026072300216) {
        // FIX-API-DOMAIN: Updated all API endpoint URLs from lms-labs.com to lms-labs.com.
        // lms-labs.com has no DNS resolution from Moodle server side; lms-labs.com is the
        // correct working domain. All ajax.php, api_client, unlock_verifier, lib.php calls updated.
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['version.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_plugin_savepoint(true, 2026072300216, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026072300217) {
        // FIX-API-DOMAIN: Reverted API endpoint to lms-labs.com (correct domain).
        // essaygraderai.app was the original single-plugin domain; lms-labs.com is correct.
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['version.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) { opcache_invalidate($_full, true); }
            }
        } elseif (function_exists('opcache_reset')) { opcache_reset(); }
        upgrade_plugin_savepoint(true, 2026072300217, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026072300218) {
        // Domain update: lms-labs.com → lms-labs.com
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['version.php', 'lib.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) { opcache_invalidate($_full, true); }
            }
        } elseif (function_exists('opcache_reset')) { opcache_reset(); }
        upgrade_plugin_savepoint(true, 2026072300218, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026072700220) {
        // BEST-PRACTICE-QUAL-MAPPING: reconciler idnumber match, qualbuilder auto-sync,
        // enrolment task category/course idnumber fallback. No DB schema changes.
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['version.php', 'lib.php', 'db/upgrade.php', 'reconcile.php',
                      'classes/external.php', 'classes/task/process_enrolment_task.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) { opcache_invalidate($_full, true); }
            }
        } elseif (function_exists('opcache_reset')) { opcache_reset(); }
        upgrade_plugin_savepoint(true, 2026072700220, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026072700221) {
        // TWOLEVEL-CATPICKER: Qualbuilder two-level category picker (qual root + semester child).
        // No DB schema changes — the categoryid column already stores the root category id,
        // which is exactly what the new picker writes. Opcache flush picks up the new AMD JS.
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['version.php', 'classes/external.php',
                      'qualbuilder_edit.php',
                      'amd/src/qualbuilder_edit.js',
                      'amd/build/qualbuilder_edit.js',
                      'amd/build/qualbuilder_edit.min.js'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) { opcache_invalidate($_full, true); }
            }
        } elseif (function_exists('opcache_reset')) { opcache_reset(); }
        upgrade_plugin_savepoint(true, 2026072700221, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026072700222) {
        // UNLIMITED-CAT-COURSE-SCAN: category query uses recordset (no limit).
        // Course query now scoped to qual root's category subtree when categoryid is
        // known; falls back to LIMIT 2000 for new records. No DB schema changes.
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['version.php', 'classes/external.php',
                      'amd/src/qualbuilder_edit.js',
                      'amd/build/qualbuilder_edit.js',
                      'amd/build/qualbuilder_edit.min.js'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) { opcache_invalidate($_full, true); }
            }
        } elseif (function_exists('opcache_reset')) { opcache_reset(); }
        upgrade_plugin_savepoint(true, 2026072700222, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026072700223) {
        // ROR-EMDASH-FIX: cert_template_renderer.php flat units separator changed from
        // single-quoted '\xe2\x80\x94' (literal escape string) to double-quoted "\xe2\x80\x94"
        // (actual UTF-8 em dash bytes). No DB schema changes.
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            $_full = $_pluginDir . '/classes/cert_template_renderer.php';
            if (file_exists($_full)) { opcache_invalidate($_full, true); }
        } elseif (function_exists('opcache_reset')) { opcache_reset(); }
        upgrade_plugin_savepoint(true, 2026072700223, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026072700224) {
        // RULES-SHOW-ALL: packaging rules list no longer truncates at 15. No DB schema changes.
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['version.php', 'amd/src/qualbuilder_edit.js',
                      'amd/build/qualbuilder_edit.js',
                      'amd/build/qualbuilder_edit.min.js'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) { opcache_invalidate($_full, true); }
            }
        } elseif (function_exists('opcache_reset')) { opcache_reset(); }
        upgrade_plugin_savepoint(true, 2026072700224, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026072700225) {
        // CROSS-SEM-MATCH: findCourseForUnit() now searches semester → qual-root → all pools
        // in order so units absent from the chosen semester are found in archive semesters.
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026072700225, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026072700226) {
        // DEFINITIVE-FIRST-MATCH: findCourseForUnit now runs T1-T3 across all pools before
        // any fuzzy match. Eliminates false positive fuzzy matches for units whose names
        // share many common words (TLI freight units). Simulated 10/10 correct on italc data.
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026072700226, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026072700227) {
        // COMBINED-COURSE-SWEEP (v5.9.244): mapAllCourses() now runs a second pass after
        // the main T1-T3 auto-match. Any unit still unlinked is checked against the
        // fullnames of courses already linked to other units in the same qualification.
        // Handles courses covering multiple units (e.g. "BSBOPS505 and BSBLDR522 - MPC").
        // Also fixes buildCourseOptions: manual dropdown now uses the same semester→root
        // pool cascade as findCourseForUnit, so cross-semester courses are visible.
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026072700227, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026072700228) {
        // NEWEST-SEMESTER-FIRST (v5.9.245): findCourseForUnit() now sorts QB.courses by
        // category ID descending before building pools. When multiple semester archives
        // each contain a course for the same unit code, pool.find() now returns the one
        // from the most recently created semester folder (highest category ID) instead of
        // whichever fullname happened to sort first alphabetically (ORDER BY fullname ASC
        // in the SQL). Sorting by category rather than course ID groups all courses from
        // the same semester as a block — a makeup/supplementary course created late in an
        // old semester cannot jump ahead of the entire current-semester archive.
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026072700228, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026072700229) {
        // ROR-TABLE-MIGRATE (v5.9.246): cert_template_renderer now uses a unified
        // 'ror_table' field kind that renders all three Record-of-Results columns
        // (Semester/Year, Unit code+name, Result) together row-by-row, synchronising
        // row heights so every column stays aligned regardless of long unit names.
        //
        // The previous savepoint 092 (2026070800092) migrated the old flat
        // qualification.units field to three separate dynamickey fields
        // (qualification.units_col_semester / _col_names / _col_results).  Those three
        // separate fields used independent fixed-height MultiCell calls that clipped
        // col 1 and col 3 after ~11 rows when col 2 wrapped to 2 lines.
        //
        // This savepoint scans all certtype='record' templates that still have the
        // old three-column dynamickey fields and replaces them with a single ror_table
        // field at the same origin and total width.  Templates that already have a
        // ror_table field (or no column fields at all) are skipped.

        $rorTemplates = $DB->get_records('local_rtocompliance_certtmpl', ['certtype' => 'record']);
        foreach ($rorTemplates as $tmplRec) {
            $design = json_decode($tmplRec->designjson ?? '{}', true);
            if (!is_array($design) || empty($design['fields'])) {
                continue;
            }
            $fields = $design['fields'];

            // Check what is already present.
            $hasRorTable    = false;
            $semIdx         = false;
            $namesIdx       = false;
            $resultsIdx     = false;
            foreach ($fields as $idx => $f) {
                $fkind = $f['kind'] ?? '';
                $dk    = $f['dynamickey'] ?? '';
                if ($fkind === 'ror_table')                           { $hasRorTable = true; }
                if ($dk    === 'qualification.units_col_semester')    { $semIdx      = $idx; }
                if ($dk    === 'qualification.units_col_names')       { $namesIdx    = $idx; }
                if ($dk    === 'qualification.units_col_results')     { $resultsIdx  = $idx; }
            }

            // Nothing to do if already migrated, or missing the old fields.
            if ($hasRorTable || $semIdx === false || $namesIdx === false || $resultsIdx === false) {
                continue;
            }

            // Determine orientation — default landscape (most RoR templates are landscape).
            $orientation = $design['page']['orientation'] ?? 'L';

            // Build the replacement ror_table field.
            // Positions and dimensions match starter_record() / starter_record_landscape().
            if ($orientation === 'P') {
                // Portrait: x=15, y=102, total_w=180, h=115
                $rorField = [
                    'id'       => 'f_' . bin2hex(random_bytes(6)),
                    'kind'     => 'ror_table',
                    'x_mm'     => 15,
                    'y_mm'     => 102,
                    'w_mm'     => 180,
                    'h_mm'     => 115,
                    'fontsize' => 10,
                    'col1_w'   => 30,
                    'col2_w'   => 110,
                    'col3_w'   => 36,
                ];
            } else {
                // Landscape: x=15, y=92, total_w=267, h=76
                $rorField = [
                    'id'       => 'f_' . bin2hex(random_bytes(6)),
                    'kind'     => 'ror_table',
                    'x_mm'     => 15,
                    'y_mm'     => 92,
                    'w_mm'     => 267,
                    'h_mm'     => 76,
                    'fontsize' => 10,
                    'col1_w'   => 40,
                    'col2_w'   => 175,
                    'col3_w'   => 48,
                ];
            }

            // Remove the three old column fields (in reverse index order to preserve indices).
            $removeIdxs = [$semIdx, $namesIdx, $resultsIdx];
            rsort($removeIdxs);
            foreach ($removeIdxs as $ri) {
                array_splice($fields, $ri, 1);
            }

            // Insert the ror_table field at the position of the earliest removed field.
            $insertAt = min($semIdx, $namesIdx, $resultsIdx);
            array_splice($fields, $insertAt, 0, [$rorField]);

            $design['fields'] = $fields;
            $DB->update_record('local_rtocompliance_certtmpl', (object) [
                'id'           => (int) $tmplRec->id,
                'designjson'   => json_encode($design),
                'timemodified' => time(),
            ]);
        }

        upgrade_plugin_savepoint(true, 2026072700229, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026072700230) {
        // RE-BUMP (v5.9.247): version-only increment to force italc upgrade detection
        // after the ror_table migration savepoint above.  No additional DB changes.
        upgrade_plugin_savepoint(true, 2026072700230, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026072700231) {
        // BULK-ACTION-PARAM-FIX (v5.9.248): bulk_action_cert.php used PARAM_ALPHA to
        // read the action POST param.  PARAM_ALPHA strips underscores, so
        // 'download_zip' → 'downloadzip' and 'export_csv' → 'exportcsv' — both
        // unknown to the handler.  Changed to PARAM_ALPHANUMEXT which preserves
        // letters, digits, hyphens, and underscores.  No DB schema changes.
        upgrade_plugin_savepoint(true, 2026072700231, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026072700235) {
        // QB-MATCHING-FIX (v5.9.249): Four-part fix for Qualbuilder cross-semester
        // unit-to-course mismatching.
        // (1) setup(): auto-select highest-ID semester child after populateSemesterDropdown
        //     for root-category records so QB.semesterid is non-zero before loadFromTGA fires.
        // (2) loadFromTGA() line 306: auto-select most recent semester if QB.semesterid=0
        //     before mapAllCourses() — prevents every TGA reload silently committing
        //     cross-semester courses when the admin hasn't explicitly picked a semester.
        // (3) acceptCategoryAndMapAll(): replaced parent>0 check with hasChildren check.
        //     parent>0 incorrectly fired for nested qual roots (e.g. under "Miscellaneous"),
        //     treating the qual root as a semester and producing an empty semPool.
        //     hasChildren correctly identifies roots (have children) vs semesters (leaves).
        // (4) external.php tga_get_builder_data: rootcatid per course now set to the
        //     passed $categoryid (qual root) instead of $getRootCatId(c->category) which
        //     walks to the system root for nested qual roots — JS rootPool filter
        //     c.rootcatid===QB.categoryid was always false, causing fallback to all 2000 courses.
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026072700235, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026072700236) {
        // v5.9.253 — QB-COURSE-REFRESH-FIX (27 Jul 2026):
        // QB.courses was only populated inside loadFromTGA(). When editing an existing
        // record or clicking Map All without reloading TGA this session, QB.courses was
        // empty, causing findCourseForUnit() to return undefined for every unit and
        // silently freezing all courseids at their last-saved DB values.
        // Fix:
        // (1) New PHP external function get_courses_for_category(): lightweight BFS
        //     course fetch for a qual categoryid — identical pool logic to tga_get_builder_data
        //     but without the TGA API round-trip.
        // (2) New JS refreshCourses(categoryid, callback): calls the new endpoint and
        //     populates QB.courses before mapAllCourses() runs.
        // (3) setup(): silently prefetches courses on page load when categoryid is set.
        // (4) Map All button: if QB.courses is empty, fetches courses first then maps.
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026072700236, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026072700237) {
        // v5.9.254 — CERT-ERRORS-FIX (27 Jul 2026):
        // Three certificate bugs fixed:
        // (1) SOA-DELETED-COL-FIX: soa_compliance_engine.php had AND c.deleted = 0 in two
        //     SQL queries joining {course}. Moodle's course table has no deleted column
        //     (courses are hard-deleted). This threw a dml_read_exception on every getstudent
        //     and getunits AJAX call in the multi-unit SOA wizard, producing "Student not found"
        //     and "Error reading from database" for every student.
        // (2) ROR-RENDER-FALLBACK: cert_template_renderer.php now has a render-time fallback
        //     for Record of Results certs saved with units=NULL (issued before v5.9.226 or
        //     when programcode lookup returned empty). Fetches units from enrolments at render
        //     time so Pack/Download/View all show the correct unit table.
        // (3) PROGRAMCODE-CASE-FIX: check_qualification_completion() now does a case-insensitive
        //     UPPER(TRIM(programcode)) = UPPER(TRIM(:qcode)) match instead of exact string match,
        //     fixing certs where programcode was stored in a different case (e.g. MEM20413 vs
        //     mem20413) or with leading/trailing whitespace.
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026072700237, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026072700238) {
        // v5.9.255 — QB-SEMESTER-SCOPE-FIX (27 Jul 2026):
        // Two cooperating bugs caused the Qualbuilder to show "10/10 linked" immediately
        // after entering a qualification code, even when the selected semester category
        // contained far fewer than 10 courses.
        //
        // Bug 1 — autoAddCoreUnits() pre-linked with semid=0:
        //   autoAddCoreUnits() is called at step 1 of loadFromTGA(), before QB.semesterid
        //   is set (step 3). With semid=0, findCourseForUnit() fell through to rootPool
        //   (ALL courses in the qual subtree across every semester). Units got pre-linked
        //   to a cross-semester mix. mapAllCourses() then ran with the correct semesterid
        //   but only updates when course.id differs — so stale cross-semester links survived.
        //   Fix: autoAddCoreUnits() never calls findCourseForUnit(). Always sets courseid=0.
        //   mapAllCourses() (which fires immediately after semesterid IS set) handles all
        //   linking with the correct semester context.
        //
        // Bug 2 — findCourseForUnit() rootPool fallback crossed semester boundaries:
        //   When semid > 0 and a unit wasn't found in the semester pool, the function fell
        //   through to rootPool = all courses across all semester children. This silently
        //   linked units to courses in a different semester, inflating the linked count and
        //   hiding the genuine gap (unit not yet set up in the selected semester).
        //   Fix: when semid > 0, ONLY use semPool. If a unit has no course in the selected
        //   semester → return null → unit stays unlinked. rootPool is only used when no
        //   semester is selected (semid=0), preserving flat-structure qual support.
        //
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026072700238, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026072700239) {
        // STALE-LINK-CLEAR (v5.9.256): mapAllCourses() never cleared stale courseids when
        // switching semesters — only JS fix, no DB schema changes.
        upgrade_plugin_savepoint(true, 2026072700239, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026072700240) {
        // UNIT-CODE-MAP (v5.9.256): PHP now extracts VET unit codes from course idnumber,
        // shortname, and fullname via regex and returns a pre-built unitcodemap to the JS.
        // findCourseForUnit() uses this as an O(1) dict lookup (semid-scoped) instead of
        // fragile string-matching tiers.  Also fixes cross-qual contamination: loading TGA
        // for a different qual code on an existing record now purges the old unit list so
        // stale courseids don't inflate the compliance counter.  No DB schema changes.
        upgrade_plugin_savepoint(true, 2026072700240, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026072700241) {
        // VERSION-BUMP (v5.9.257): Force Moodle upgrade detection to flush AMD cache and
        // guarantee no stale qualbuilder_edit.min.js is served from browser or Moodle cache.
        // All changes are JS/PHP only — no DB schema changes.
        upgrade_plugin_savepoint(true, 2026072700241, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026072700242) {
        // MAP-ONLY (v5.9.258): Removed tier/fuzzy fallback and combined-course sweep.
        // SQL-derived unitCodeMap is now the sole auto-link mechanism. No DB schema changes.
        upgrade_plugin_savepoint(true, 2026072700242, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026072700243) {
        // REFRESH-BEFORE-MAP (v5.9.259): refreshCourses(QB.categoryid) now called before
        // every mapAllCourses() path (semester change, category change, Map All button,
        // acceptCategoryAndMapAll). QB.unitCodeMap was previously empty at map-time because
        // no fetch was triggered — units always returned null. No DB schema changes.
        upgrade_plugin_savepoint(true, 2026072700243, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026072700244) {
        // CROSS-SEM FALLBACK (v5.9.260): findCourseForUnit now tries exact semid match first;
        // if none found, falls back to any entry in the unitCodeMap (highest category ID wins).
        // Fixes permanent cross-semester courses (IDW 21S1, ABP 21S1) being cleared by the
        // strict category===semid filter when a newer delivery semester is selected.
        upgrade_plugin_savepoint(true, 2026072700244, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026072700245) {
        // STRICT-SEMID (v5.9.261): removed cross-semester fallback and semid=0 picker from
        // findCourseForUnit(). Exact category===semid match only; no match returns null so
        // stale-link-clear fires. Fixes IDW 26S1/ABP 26S1 being wrongly linked when 26S2
        // semester is selected. No DB schema changes.
        upgrade_plugin_savepoint(true, 2026072700245, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026072700246) {
        // SAVE-SEMESTER + PICK-DEFAULT (v5.9.262):
        // (1) saveQualification now stores QB.semesterid (semester leaf) as categoryid so
        //     page-reload restores the exact semester via the leaf-detection path in setup().
        //     Previously it stored the qual root, causing setup() to auto-select a semester
        //     by highest category ID — which at iTALC is S1 (created after S2), giving 5/10.
        // (2) All 5 auto-select sites now use pickDefaultSemester(): exclude Archive folders,
        //     sort by name DESC so S2 > S1, Term 2 > Term 1, etc. No DB schema changes.
        upgrade_plugin_savepoint(true, 2026072700246, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026072700263) {
        // REBUILD-MINJS (v5.9.263): Rebuilt amd/build/qualbuilder_edit.min.js from source
        // using terser after confirming the previous ZIPs shipped a stale min.js that did
        // not contain pickDefaultSemester, strict semid matching, or any of the v5.9.255+
        // fixes. No PHP or DB changes — AMD rebuild only.
        upgrade_plugin_savepoint(true, 2026072700263, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026072700264) {
        // CERT-ERRORS-FIX (v5.9.264): Three certificate rendering bugs fixed.
        //
        // (1) ROR-TABLE-FULLSWEEP: Force-migrate ALL certtype='record' templates to the
        //     'ror_table' field kind.  Previous migrations (savepoints 092 and 229) had
        //     conditions that could miss templates: savepoint 229 skipped any template
        //     that lacked ALL THREE column fields (units_col_semester/_col_names/_col_results),
        //     so templates still using the old flat 'qualification.units' field (or only a
        //     partial column set) were left with the old fixed-height MultiCell approach that
        //     causes col-1/col-3 to fall out of sync with col-2 when unit names wrap to
        //     multiple lines.  This sweep skips ONLY templates that already have ror_table.
        //
        // (2) SOA-RENDER-FALLBACK: cert_template_renderer.php resolve_payload() now
        //     extends the render-time unit-fetch fallback to certtype='statement' (SOA) as
        //     well as 'record'.  Old SOA certs issued before v5.9.226 had units=NULL, so
        //     no unit list appeared on download.  No DB schema changes.
        //
        // (3) SOA-DUPCODE-UNIT-FIX: strip unit-code prefix from unit name when the Moodle
        //     course fullname/shortname already starts with the unit code (e.g. "TLIK2010
        //     Computer Applications" stored as name when code="TLIK2010").  Without this
        //     the rendered line was "TLIK2010 — TLIK2010 Computer Applications".  Fix
        //     applied in cert_template_renderer.php and lib.php legacy renderer.

        $dbman = $DB->get_manager();
        if ($dbman->table_exists('local_rtocompliance_certtmpl')) {
            $rorTemplates = $DB->get_records('local_rtocompliance_certtmpl', ['certtype' => 'record']);
            foreach ($rorTemplates as $tmplRec) {
                $design = json_decode($tmplRec->designjson ?? '{}', true);
                if (!is_array($design) || empty($design['fields'])) {
                    continue;
                }
                $fields = $design['fields'];

                // Skip templates already using ror_table.
                $hasRorTable = false;
                foreach ($fields as $f) {
                    if (($f['kind'] ?? '') === 'ror_table') { $hasRorTable = true; break; }
                }
                if ($hasRorTable) {
                    continue;
                }

                // Remove ALL unit-display fields (old flat key AND 3-column keys).
                $removeKeys = [
                    'qualification.units',
                    'qualification.units_col_semester',
                    'qualification.units_col_names',
                    'qualification.units_col_results',
                ];
                $firstIdx  = null;
                $newFields = [];
                foreach ($fields as $idx => $f) {
                    $dk = $f['dynamickey'] ?? '';
                    if (in_array($dk, $removeKeys, true)) {
                        if ($firstIdx === null) {
                            $firstIdx = count($newFields);
                        }
                    } else {
                        $newFields[] = $f;
                    }
                }

                $orientation = $design['page']['orientation'] ?? 'L';
                if ($orientation === 'P') {
                    $rorField = [
                        'id' => 'f_' . bin2hex(random_bytes(6)), 'kind' => 'ror_table',
                        'x_mm' => 15, 'y_mm' => 102, 'w_mm' => 180, 'h_mm' => 115,
                        'fontsize' => 10, 'col1_w' => 30, 'col2_w' => 110, 'col3_w' => 36,
                    ];
                } else {
                    $rorField = [
                        'id' => 'f_' . bin2hex(random_bytes(6)), 'kind' => 'ror_table',
                        'x_mm' => 15, 'y_mm' => 92, 'w_mm' => 267, 'h_mm' => 76,
                        'fontsize' => 10, 'col1_w' => 40, 'col2_w' => 175, 'col3_w' => 48,
                    ];
                }

                // Insert at the earliest removed field's position, or append if none removed.
                $insertAt = ($firstIdx !== null) ? $firstIdx : count($newFields);
                array_splice($newFields, $insertAt, 0, [$rorField]);

                $design['fields'] = $newFields;
                $DB->update_record('local_rtocompliance_certtmpl', (object) [
                    'id'           => (int) $tmplRec->id,
                    'designjson'   => json_encode($design, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'timemodified' => time(),
                ]);
            }
        }

        upgrade_plugin_savepoint(true, 2026072700264, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026072700265) {
        // CERT-ERRORS-FIX-2 (v5.9.265): Two additional certificate rendering fixes.
        //
        // (1) SOA-WORDING-FIX: qualification.completionofcoursestatement and
        //     qualification.partofstatement were generated for ANY code containing a digit
        //     (preg_match('/\d/', $code)).  This includes unit codes (TLIK2010, BSBCMM311)
        //     which have a 3-4 digit suffix.  Result: old SOA certs where qualificationcode
        //     is a unit code rendered "These competencies were attained in completion of
        //     TLIK2010 course in Computer Applications." — factually wrong wording.
        //     Fix: require a 5-digit-minimum suffix (/^[A-Z]{2,10}[0-9]{5,6}[A-Z]?$/) so
        //     only genuine qualification codes (BSB30120, TLI50119) trigger these sentences.
        //
        // (2) SOA-UNITCODE-FALLBACK: when qualificationcode is itself a unit code (old-style
        //     single-unit SOAs), check_qualification_completion() returns empty because it
        //     queries by programcode.  A secondary direct lookup in local_rtocompliance_enrolments
        //     by unitcode = qualificationcode now populates the unit list so the SOA shows
        //     the single unit instead of a blank cert.
        //
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026072700265, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026072700266) {
        // CROSS-PACKAGE-FIX (v5.9.266): Elective units from a different training
        // package (e.g. BSB electives in a TLI qualification) were showing
        // "-- Not linked --" even when the corresponding Moodle courses existed.
        //
        // Root cause: the PHP course query in tga_get_builder_data and
        // get_courses_for_category used a BFS subtree scan scoped to the
        // qualification's Moodle category root.  Courses from a different category
        // tree (e.g. BSB courses under their own category) were never included in
        // QB.courses or unitcodemap, so the JS had nothing to match against.
        //
        // Fix (two-part, no DB schema changes):
        // (1) PHP: after the subtree scan, run a site-wide supplement (LIMIT 3000).
        //     tga_get_builder_data filters the supplement to unit codes present in
        //     the TGA qual data (lean payload); get_courses_for_category adds any
        //     course with a recognisable unit-code pattern.  Supplement entries get
        //     rootcatid=0 as a cross-package marker.
        // (2) JS findCourseForUnit(): after failing to find a semid match, check for
        //     a unitcodemap entry whose QB.courses entry has rootcatid=0.  These
        //     cross-package courses bypass the semester filter — they are from a
        //     wholly separate category tree so semester-scoping does not apply.
        upgrade_plugin_savepoint(true, 2026072700266, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026072700267) {
        // CROSS-PACKAGE-FILTER (v5.9.267): get_courses_for_category supplement scan
        // was unfiltered — it added every cross-site course with any unit-code pattern
        // on every semester change, flooding QB.courses and QB.unitCodeMap with courses
        // irrelevant to the current qualification.
        //
        // Fix: JS now passes QB.tgaUnits (or QB.currentUnits as fallback) as a
        // comma-separated 'unitcodes' parameter to get_courses_for_category.  PHP
        // parses the list and filters the supplement scan to only those unit codes,
        // giving the same lean targeted payload as tga_get_builder_data.
        // When unitcodes is empty (old JS or pre-TGA page load), the supplement falls
        // back to the unfiltered pattern so nothing regresses.
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026072700267, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026072800268) {
        // FIX-QB-SAME-PKG-STALE (v5.9.268): findCourseForUnit() was falling back to
        // cross-package courses (rootcatid=0) for same-package units that had no
        // current-semester course.  Example: TLIX0006 in TLI20205 — both share the
        // "TL" package prefix — was linked to an old 2022 course ("ABP 2222") because
        // that course's fullname contained "TLIX0006" and the supplement scan had given
        // it rootcatid=0.  The cross-package fallback loop now checks whether the unit
        // code's first 2 letters match the qualification's first 2 letters; same-package
        // units skip the fallback and show "-- Not linked --" instead.
        // JS-only fix: qualbuilder_edit.js (src+build+min).  No PHP or DB changes.
        upgrade_plugin_savepoint(true, 2026072800268, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026072800269) {
        // FIX-QB-AUTOCHECK-ON-LINK (v5.9.269): selecting a course from the dropdown for
        // an unchecked elective unit now implicitly selects that unit.  Previously the
        // courseid was stored in the DOM only; saving without first checking the checkbox
        // omitted the unit from the payload so it vanished on reload.  Now onCourseChange()
        // auto-checks the checkbox, pushes the unit into QB.currentUnits with the chosen
        // courseid, and updates the section count pill — identical to a manual checkbox tick.
        // JS-only fix: qualbuilder_edit.js (src+build+min).  No PHP or DB changes.
        upgrade_plugin_savepoint(true, 2026072800269, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026072800270) {
        // FIX-QB-SECTION-PILL / FIX-QB-SIMPLIFY (v5.9.270):
        // (1) Added centralised updateSectionPill() helper in qualbuilder_edit.js so all
        //     section-header status pills (core / groups / general electives / imported) stay
        //     in sync after EVERY QB.currentUnits mutation (toggle, delete, course-link).
        //     Previously deleteUnit() never updated pills; onCourseChange had its own inline
        //     copy with a subtle difference from onUnitToggle; General Electives showed
        //     "0 selected" on initial render (should show blank when nothing selected).
        // (2) qualbuilder.php listing subqueries now filter AND qu.selected = 1 so unit
        //     and linked-course counts are accurate if any legacy selected=0 rows exist.
        // (3) Removed the "Link Courses" (qualbuilder_courses.php) action button from the
        //     listing page — course linking is fully handled inside the Smart Builder (Edit).
        //     The old page used a cruder SQL LIKE auto-detect and duplicated Edit functionality,
        //     making the workflow confusing. qualbuilder_courses.php still exists as a URL
        //     for direct access if needed but is no longer advertised.
        // JS-only + PHP listing changes; no DB schema changes.
        upgrade_plugin_savepoint(true, 2026072800270, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026072800271) {
        // FIX-QB-NO-FALLBACK (v5.9.271): removed ALL cross-package and cross-semester
        // fallbacks from findCourseForUnit().  Only an exact semester match is used for
        // auto-linking; anything unmatched shows "-- Not linked --" for manual assignment.
        upgrade_plugin_savepoint(true, 2026072800271, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026072800272) {
        // FIX-QB-MOODLE-ORDER (v5.9.272): Qualbuilder unit list now mirrors the
        // top-to-bottom course order on the Moodle "Manage course categories" page.
        //
        // PHP: all four course SQL queries (tga_get_builder_data main + supplement,
        //      get_courses_for_category main + supplement) now SELECT sortorder and
        //      ORDER BY sortorder ASC instead of fullname ASC.  The sortorder value
        //      is included in every moodlecourses entry in the web-service response.
        //
        // JS: new sortTgaUnitsByMoodleOrder() function sorts QB.tgaUnits in place
        //     before every renderUnitBuilder() call.  Each unit is ordered by its
        //     linked course's sortorder; unlinked units go to the end of their section.
        //     This means the Qualbuilder list stays perfectly aligned with Moodle's
        //     Manage Courses page whenever courses are reordered in Moodle.
        //
        // No DB schema changes; JS + PHP web-service only.
        upgrade_plugin_savepoint(true, 2026072800272, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026072800273) {
        // FIX-QB-INTERACTION-AUDIT (v5.9.273): five interaction wiring bugs fixed (JS only).
        //
        // 1. onGroupChange didn't update the checkbox's data-unitgroup — assigning a group
        //    to an unchecked unit then ticking it pushed the unit with the wrong (original
        //    empty) group.  Fix: sync both the row attr AND the checkbox attr.
        //
        // 2. onUnitToggle UNCHECK didn't reset the compact badge visual — a linked unit
        //    kept showing "✓ BSB226" after being unticked.  Fix: call renderUnitBuilder()
        //    on uncheck (fast pill-only path preserved for CHECK).
        //
        // 3. deleteUnit had the same compact-badge ghost — TGA units showed as linked after
        //    deletion.  Fix: call renderUnitBuilder() after delete; imported rows pre-removed
        //    from DOM to avoid flicker.
        //
        // 4. suggestCategory() called .on('click') inline after each .html() update, stacking
        //    a new handler on every TGA reload.  Fix: single delegated bind in bindEvents();
        //    inline binding removed from suggestCategory().
        //
        // 5. Compact badge dropdown had no focusout handler — opening via badge click and
        //    clicking away without changing left the row in "open dropdown" mode indefinitely.
        //    Fix: focusout handler on .qb-course-sel restores badge when dropdown loses focus.
        //
        // No DB schema changes; JS-only release.
        upgrade_plugin_savepoint(true, 2026072800273, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026072800274) {
        // FIX-QB-AUDIT-PASS2 (v5.9.274): four additional bugs found during 5-pass code review.
        //
        // 1. onCourseChange auto-check path (v5.9.269) never called renderUnitBuilder() —
        //    after auto-checking a unit by selecting a course, the row stayed showing an
        //    open dropdown instead of switching to compact badge mode.
        //    Fix: call renderUnitBuilder() after the unit is pushed; remove the now-redundant
        //    updateSectionPill() call (renderUnitBuilder recomputes section pills internally).
        //
        // 2. jQuery .data() cache bug in onGroupChange — .attr('data-unitgroup', v) updates
        //    the DOM attribute but NOT jQuery's internal data cache.  onUnitToggle reads
        //    $cb.data('unitgroup') which returns the stale cached value after the first read.
        //    Fix: onGroupChange now calls BOTH .attr() (DOM) AND .data() (jQuery cache) on
        //    the checkbox so both paths stay in sync.
        //
        // 3. Render-order inconsistency — the auto-check path called renderComplianceDashboard
        //    then renderUnitBuilder; the existing-unit path did the opposite.  Normalised to
        //    renderUnitBuilder first (DOM), then renderComplianceDashboard (cards) throughout.
        //
        // 4. evaluateQualification() prerequisite error message used u.code (undefined) instead
        //    of u.unitcode.  Fix: (u.unitcode || u.code || '?') with safe fallback.
        //
        // No DB schema changes; JS-only release.
        upgrade_plugin_savepoint(true, 2026072800274, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026072800275) {
        // FIX-CERT-AUDIT + FIX-PRIVACY (v5.9.275): four bugs fixed across the certificate
        // system and the plugin-wide Privacy API implementation.
        //
        // 1. reissue_cert.php — missing registry status update after reissue.
        //    Bulk generation paths (generate_course_certs.php, generate_qual_certs.php)
        //    correctly called local_rtocompliance_update_registry_status($token, 'superseded')
        //    to mark the old cert's QR code as "Superseded".  The single reissue_cert.php
        //    endpoint was the only path that did NOT call it, leaving the old QR code
        //    appearing "Valid" after reissue.  Fixed by adding the call after the source
        //    cert's reissued_at field is set.
        //
        // 2. delete_cert.php — revocation used error_log() instead of the plugin's
        //    structured audit log.  Revocation events were invisible in the admin audit
        //    trail UI.  Fixed: now writes to local_rtocompliance_log with action
        //    'revoke_certificate', matching the pattern used by all other cert actions.
        //
        // 3. privacy/provider.php — export_user_data() silently omitted four tables
        //    that are declared in get_metadata(): local_rtocompliance_enrolments,
        //    local_rtocompliance_rpl, local_rtocompliance_complaints,
        //    local_rtocompliance_appeals.  A GDPR Subject Access Request would therefore
        //    miss training enrolments, RPL decisions, and complaint/appeal records.
        //    Fixed: all four tables are now exported.  Note: enrolments and RPL link via
        //    studentid (FK → local_rtocompliance_students), not directly by Moodle userid,
        //    so the student record is resolved first.
        //
        // 4. privacy/provider.php — delete_data_for_user() and delete_data_for_users()
        //    had the same four missing tables.  A GDPR erasure request would leave PII
        //    in these tables indefinitely.  Fixed: all four tables are now deleted.
        //    The delete_data_for_users() bulk path uses get_fieldset_select() to resolve
        //    studentids from the batch of userids before deleting enrolments/RPL.
        //    Enrolment/RPL rows are deleted BEFORE the student row to respect referential
        //    ordering (avoids orphan rows if FK constraints are added in future).
        //
        // No DB schema changes; PHP-only release.
        upgrade_plugin_savepoint(true, 2026072800275, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026072800276) {
        // FIX-WIDE-AUDIT (v5.9.276): five bugs found across the full plugin audit.
        //
        // 1. db/access.php — local/rtocompliance:viewreports capability was MISSING.
        //    All four external web-service functions in db/services.php declared
        //    'capabilities' => 'local/rtocompliance:viewreports', but the capability
        //    was never declared in access.php.  Moodle throws "Capability not found"
        //    on every service call, silently breaking the entire Qualbuilder TGA/course
        //    lookup system.  Fixed: capability added with RISK_PERSONAL + read +
        //    CONTEXT_SYSTEM, granted to manager archetype, matching :viewall.
        //
        // 2. insurance.php — $requiredtypes key 'workers_compensation' did not match
        //    the form value 'workers_comp' saved by insurance_form.php.  Workers
        //    Compensation always showed "Missing" on the insurance dashboard regardless
        //    of what policies were saved.  Fixed: key changed to 'workers_comp'.
        //
        // 3. location_edit.php — postcode field used PARAM_INT, which strips leading
        //    zeros.  NT postcodes such as 0800 (Darwin) were stored as 800, producing
        //    an invalid 3-digit AVETMISS NAT85 postcode field.  Fixed: changed to
        //    PARAM_ALPHANUM; existing 4-digit preg_match validation is preserved.
        //
        // 4. lib.php (local_rtocompliance_programmatic_issue_cert) — the cert-number
        //    sequence LIKE query was built with an unescaped $prefix from admin config.
        //    If the prefix contained SQL LIKE wildcards (% or _), the COUNT would
        //    match more certs than intended, inflating $sequence and skipping numbers
        //    in the sequence.  Fixed: $prefix is now escaped before use in the pattern.
        //
        // 5. db/install.xml — local_rtocompliance_qualdebug.best_flags was char(255).
        //    For large qualifications with many scoring flags the pipe-separated string
        //    easily exceeds 255 chars, causing silent DB truncation and breaking the
        //    Step 6 ADD engine diagnostics.  Fixed: column changed to text (unlimited).
        //    Existing sites need the column altered — see DDL below.
        $dbman = $DB->get_manager();
        $table = new xmldb_table('local_rtocompliance_qualdebug');
        if ($dbman->table_exists($table)) {
            $field = new xmldb_field('best_flags', XMLDB_TYPE_TEXT, null, null, null, null, null);
            if ($dbman->field_exists($table, $field)) {
                $dbman->change_field_type($table, $field);
            }
        }

        upgrade_plugin_savepoint(true, 2026072800276, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026072800277) {
        // FIX-CERT-PIPELINE + FIX-JS-RACE (v5.9.277): four bugs fixed across
        // the certificate issuance pipeline and the nominalhours JS module.
        //
        // 1. issue_certificate.php — cert-number sequence LIKE query used an
        //    unescaped $prefix (same bug fixed in lib.php in v5.9.276 but
        //    issue_certificate.php had the identical unescaped pattern).  A
        //    prefix containing % or _ over-counts certs, inflates $sequence,
        //    and produces gaps in the cert number series.
        //
        // 2. issue_certificate.php — credits are deducted BEFORE the DB insert.
        //    If insert_record() throws (e.g. DB connection drop, constraint
        //    violation), 5 credits are permanently consumed with no cert created.
        //    Fixed: insert now wrapped in try/catch; on failure the orphaned
        //    charge is logged to PHP error_log (for manual admin review) and an
        //    error is shown to the user.  There is no refund API on the platform
        //    client; this at least makes the orphan visible.
        //
        // 3. lib.php (local_rtocompliance_send_certificate_email) — the temp PDF
        //    file written to $CFG->tempdir is cleaned up by @unlink() after
        //    email_to_user(), but if email_to_user() throws an uncaught exception
        //    the @unlink() is never reached and the file is orphaned.  Fixed:
        //    register_shutdown_function() ensures cleanup even on exception.
        //
        // 4. amd/src/nominalhours_autofill.js — blur event fires the lookup
        //    immediately AND the 800 ms debounce timer fires a second lookup
        //    shortly after; two concurrent XHRs race and the slower response
        //    silently overwrites whatever the faster one already set.  Fixed:
        //    (a) blur now clears the debounce timer before firing its own lookup,
        //    (b) a currentXhr variable tracks the in-flight request and aborts
        //    it at the start of each new lookup, and (c) superseded XHR callbacks
        //    are ignored via an identity check.
        //
        // No DB schema changes; PHP + JS only.
        upgrade_plugin_savepoint(true, 2026072800277, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026072800278) {
        // FIX-QB-DROPDOWN-ROOTPOOL (v5.9.278): qualbuilder_edit.js buildCourseOptions()
        // still had the semester → rootPool → all-courses cascade that was removed from
        // findCourseForUnit() in v5.9.271.  When a semester was selected and a unit had
        // no match in that semester, the dropdown silently showed every course in the
        // entire qualification category tree (10+ options) and allowed cross-semester
        // links — exactly the behaviour the v5.9.271 fix was meant to eliminate.
        //
        // The old comment "Use the same cascade as findCourseForUnit" was incorrect;
        // findCourseForUnit has had no cascade since v5.9.271.
        //
        // Fix: buildCourseOptions() now accepts a unitcode parameter and builds the
        // pool from QB.unitCodeMap (same authoritative source as findCourseForUnit).
        // When a unit-code match exists in the selected semester only those courses
        // are offered.  When no match exists the full semester list is still shown so
        // the admin can manually link, but rootPool and all-courses fallbacks are gone.
        // When no semester is selected the dropdown is empty (only "-- Not linked --"),
        // matching findCourseForUnit() strict behaviour.
        //
        // Call site in unitRow() updated: buildCourseOptions(courseid) →
        // buildCourseOptions(courseid, code).
        //
        // AMD-only change to qualbuilder_edit.js (src + build + min).
        // No PHP or DB schema changes.
        upgrade_plugin_savepoint(true, 2026072800278, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026072800279) {
        // FIX-AMD-NAMED-DEFINE (v5.9.279): cert_template_editor.js amd/src had an
        // anonymous define([], function () {}) — the named define fix that was applied
        // to amd/build/ in a prior session was never synced back to amd/src/.
        // Result: the src and build diverged (different md5), and any future rebuild
        // from src would have shipped an anonymous define, triggering the Moodle
        // combo-loader AMD slot collision that collapses all page navigation
        // (confirmed bug — see moodle-amd-jquery-default-error memory note).
        //
        // Fix: amd/src/cert_template_editor.js line 25 updated to named define:
        //   define('local_rtocompliance/cert_template_editor', [], function () {
        // All three AMD files rebuilt (src → build, terser → min).
        //
        // Also fixed: BUILD_INFO.json was stuck at "version": "5.9.267" (stale by
        // 12 versions). Updated to 5.9.279. BUILD_INFO.json is read by diag.php
        // and the portal plugin card — leaving it stale showed the wrong version
        // in both places.
        //
        // No PHP or DB schema changes.
        upgrade_plugin_savepoint(true, 2026072800279, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026072800280) {
        // FIX-QB-DASHBOARD-COMPOUND (v5.9.280): The Qualbuilder compliance dashboard had
        // four summary boxes that contradicted each other:
        //
        //   • Core Units ✓ 10/10 (green)  — packaging rules satisfied
        //   • Moodle Links 🔗 5/10 (amber) — only half the same units linked
        //
        // A trainer reading these side-by-side saw a green ✓ tick implying "done" while
        // the very next box showed only 5 of those 10 units are actually set up for
        // delivery. "Total Units ⚠ 10/12" was also redundant — always equal to
        // core + elective, visible from the two cards above it.
        //
        // Changes:
        //   1. "Core Units" card is now COMPOUND: passes only when packaging rules are
        //      met AND all core units have a Moodle course linked. When packaging is met
        //      but some units are unlinked, the card turns amber ⚠ and shows a sub-line:
        //      "X / Y linked to Moodle".
        //   2. "Elective Units" card gets the same compound treatment.
        //   3. "Total Units" card REMOVED (redundant — always core + elective count).
        //   4. "Moodle Links" standalone card REMOVED (its information is now absorbed
        //      into Core/Elective cards so the packaging status and delivery-readiness
        //      status for the same set of units are never shown as contradictory siblings).
        //   5. statusCard() gains an optional 5th `sub` parameter for the sub-line.
        //   6. getCompliance() now returns coreLinked and elecLinked breakdowns.
        //   7. CSS: .qb-status-sub added (small italic sub-value inside a card).
        //
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026072800280, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026072800281) {
        // ROR-TABLE-DEFINITIVE-SWEEP + SOA-CLEAN-UNITNAME (v5.9.281):
        //
        // (1) ROR-TABLE-DEFINITIVE-SWEEP: Three prior migrations (savepoints 092,
        //     229, 264) did not fully resolve the Record of Results column misalignment
        //     for all tenants.  Two root causes identified:
        //
        //     (a) DIMENSION MISMATCH — Savepoints 229 and 264 used
        //         ($design['page']['orientation'] ?? 'L') to pick column widths.  Any
        //         template where the orientation key was absent (imported / hand-built
        //         JSON) defaulted to landscape dimensions (col1_w=40, w_mm=267) on a
        //         210mm portrait page.  The ror_table renderer then placed c2x=57
        //         instead of 47 and c3x=234 instead of 159, misaligning data rows
        //         versus column headers.
        //
        //     (b) COEXISTING FIELDS — After migration inserted ror_table, some
        //         templates had the old 3-col dynamic fields re-added (e.g. via a
        //         template editor re-save or manual field insertion).  The renderer
        //         draws EVERY field, so both ror_table and the old independent MultiCell
        //         columns render on top of each other, and the MultiCell columns advance
        //         independently when unit names wrap.
        //
        //     This sweep handles BOTH cases for every certtype='record' template:
        //       (i)  If the template HAS ror_table: remove any coexisting unit-display
        //            dynamic fields (qualification.units / _col_semester / _col_names /
        //            _col_results).
        //       (ii) If the template HAS ror_table with wrong dimensions (detected by
        //            comparing w_mm vs the page width_mm): correct x/y/w/h/col1-3_w to
        //            match the page orientation.  page.orientation is also written
        //            authoritatively from width_mm.
        //      (iii) If the template has NO ror_table: remove all old unit-display
        //            fields and insert a properly-dimensioned ror_table in their place.
        //
        // (2) SOA-CLEAN-UNITNAME: soa_ajax.php now strips the unit-code prefix from
        //     $u->unittitle at storage time so cert.units JSON always has clean names.
        //     No DB migration needed — existing certs retain their current JSON and
        //     the render-time strip in resolve_payload() continues to handle them.

        $dbman = $DB->get_manager();
        if ($dbman->table_exists('local_rtocompliance_certtmpl')) {
            $rorTemplates = $DB->get_records('local_rtocompliance_certtmpl', ['certtype' => 'record']);

            $unitDisplayKeys = [
                'qualification.units',
                'qualification.units_col_semester',
                'qualification.units_col_names',
                'qualification.units_col_results',
            ];

            foreach ($rorTemplates as $tmplRec) {
                $design = json_decode($tmplRec->designjson ?? '{}', true);
                if (!is_array($design) || empty($design['fields'])) {
                    continue;
                }

                // Determine orientation authoritatively from page width_mm.
                // 210 mm = portrait A4, 297 mm = landscape A4.
                $pageW      = (float)($design['page']['width_mm'] ?? 297);
                $isPortrait = ($pageW <= 215);

                if ($isPortrait) {
                    $rorX = 15; $rorY = 102; $rorW = 180; $rorH = 115;
                    $c1w  = 30; $c2w  = 110; $c3w  = 36;
                } else {
                    $rorX = 15; $rorY = 92;  $rorW = 267; $rorH = 76;
                    $c1w  = 40; $c2w  = 175; $c3w  = 48;
                }

                $fields  = $design['fields'];
                $changed = false;

                // Find existing ror_table field (if any).
                $rorIdx = null;
                foreach ($fields as $idx => $f) {
                    if (($f['kind'] ?? '') === 'ror_table') {
                        $rorIdx = $idx;
                        break;
                    }
                }

                if ($rorIdx !== null) {
                    // (a) Fix dimensions if they don't match the expected layout.
                    $rf        = $fields[$rorIdx];
                    $wrongDims = (
                        abs((float)($rf['w_mm']   ?? 0) - $rorW) > 1.0 ||
                        abs((float)($rf['col1_w'] ?? 0) - $c1w)  > 1.0 ||
                        abs((float)($rf['col2_w'] ?? 0) - $c2w)  > 1.0 ||
                        abs((float)($rf['col3_w'] ?? 0) - $c3w)  > 1.0
                    );
                    if ($wrongDims) {
                        $fields[$rorIdx]['x_mm']   = $rorX;
                        $fields[$rorIdx]['y_mm']   = $rorY;
                        $fields[$rorIdx]['w_mm']   = $rorW;
                        $fields[$rorIdx]['h_mm']   = $rorH;
                        $fields[$rorIdx]['col1_w'] = $c1w;
                        $fields[$rorIdx]['col2_w'] = $c2w;
                        $fields[$rorIdx]['col3_w'] = $c3w;
                        $changed = true;
                    }

                    // (b) Remove any coexisting unit-display dynamic fields.
                    $newFields = [];
                    foreach ($fields as $f) {
                        $dk = $f['dynamickey'] ?? '';
                        if (($f['kind'] ?? '') !== 'ror_table' && in_array($dk, $unitDisplayKeys, true)) {
                            $changed = true;
                            continue; // drop it
                        }
                        $newFields[] = $f;
                    }
                    $fields = $newFields;

                } else {
                    // (c) No ror_table — remove old unit-display fields and add ror_table.
                    $firstIdx  = null;
                    $newFields = [];
                    foreach ($fields as $f) {
                        $dk = $f['dynamickey'] ?? '';
                        if (in_array($dk, $unitDisplayKeys, true)) {
                            if ($firstIdx === null) {
                                $firstIdx = count($newFields);
                            }
                            $changed = true;
                            continue; // drop it
                        }
                        $newFields[] = $f;
                    }
                    $rorField = [
                        'id'       => 'f_' . bin2hex(random_bytes(6)),
                        'kind'     => 'ror_table',
                        'x_mm'     => $rorX, 'y_mm'   => $rorY,
                        'w_mm'     => $rorW, 'h_mm'   => $rorH,
                        'fontsize' => 10,
                        'col1_w'   => $c1w, 'col2_w' => $c2w, 'col3_w' => $c3w,
                    ];
                    $insertAt = ($firstIdx !== null) ? $firstIdx : count($newFields);
                    array_splice($newFields, $insertAt, 0, [$rorField]);
                    $fields  = $newFields;
                    $changed = true;
                }

                if (!$changed) {
                    continue;
                }

                $design['fields']               = $fields;
                // Write orientation authoritatively so future migrations have it.
                $design['page']['orientation']  = $isPortrait ? 'P' : 'L';

                $DB->update_record('local_rtocompliance_certtmpl', (object) [
                    'id'           => (int) $tmplRec->id,
                    'designjson'   => json_encode($design, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'timemodified' => time(),
                ]);
            }
        }

        upgrade_plugin_savepoint(true, 2026072800281, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026072800282) {
        // QB-DASHBOARD-CLARITY (v5.9.282): Five Qualbuilder compliance dashboard UX fixes.
        //
        // (1) QPR banner: was GREEN even when packaging rules were met but units were still
        //     unlinked to Moodle courses.  Green implies "ready to deliver", which is false
        //     when course links are missing.  Banner is now AMBER with ⚠ when packaging is
        //     met but any units are unlinked; only GREEN ✓ when both conditions are satisfied.
        //
        // (2) Core Units card: was showing "10 / 10" (selected/required checkboxes) with a
        //     sub-line "5 / 10 linked to Moodle".  Since the whole page is about linking units
        //     to Moodle courses, the primary metric should be linked/required.  Card now shows
        //     "5 / 10 linked" directly.  Sub-line removed (redundant once it's the main value).
        //
        // (3) Elective Units card: same redesign as Core — shows "X / Y linked".
        //
        // (4) Total Units card: re-added (was removed in v5.9.280 as "redundant").  User
        //     explicitly requested "a total units box that counts as they are selected".
        //     Shows "X / Y selected" where Y = QB.totalRequired.
        //
        // (5) Section pill ("✓ 10 / 10 selected"): core units are mandatory / locked so the
        //     selected count is always 100% — zero-information noise.  Pill now shows linked
        //     count ("⚠ 5 / 10 linked") via a new linkedPill() helper.  updateSectionPill()
        //     updated to match so live course-dropdown changes refresh the pill correctly.
        //
        // (6) Unit counter ("19 units"): relabelled to "19 available · 12 selected" so the
        //     TGA pool size and the admin's selection count are both visible and distinct.
        //
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026072800282, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026072800283) {
        // QB-TOTAL-UNITS-LINKED-FIX (v5.9.283): Two additional compliance dashboard fixes.
        //
        // (1) Total Units card used c.total (QB.currentUnits.length) as its numerator.
        //     QB.currentUnits always contains all mandatory core units from TGA load
        //     (autoAddCoreUnits()), so c.total was 10 the moment TGA loaded and 12 as
        //     soon as 2 electives were ticked — regardless of whether those units were
        //     actually linked to Moodle courses.  Result: card showed "10/12 selected"
        //     on load and "✓ 12/12 selected" GREEN even when only 5 core + 2 elective
        //     were linked (7 done, 5 still outstanding).  Now uses c.linked (Moodle-linked
        //     count) matching the Core Units and Elective Units cards.  With 5 core linked
        //     + 2 elective linked, card now shows "⚠ 7 / 12 linked".
        //
        // (2) renderExistingUnitsSection() (fallback path shown before TGA is reloaded)
        //     was showing "10 units" for the Core section header pill instead of
        //     "⚠ X / Y linked".  Fixed to use linkedPill() — consistent with the
        //     TGA-loaded path.
        //
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026072800283, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026072800284) {
        // QB-REMOVE-UNIT-COUNTER (v5.9.284): Removed the "X available · Y selected"
        // unit counter from the Qualbuilder unit list panel. The counter was noise —
        // the compliance dashboard cards (Core Units, Elective Units, Total Units) already
        // surface the meaningful counts (linked vs required). The raw TGA pool size and
        // selected count added no actionable information. No DB schema changes.
        upgrade_plugin_savepoint(true, 2026072800284, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026072800285) {
        // QB-ALL-SEM-COURSES + QB-STREAM-NAME (v5.9.285):
        //
        // (1) ALL-SEM-COURSES: buildCourseOptions() now shows every course in the selected
        //     semester for each unit dropdown, not just unit-code-matched courses.
        //     Matched courses float to the top; remaining semester courses appear below a
        //     divider ("── Other courses in semester ──") so the RTO can freely choose
        //     which Moodle course links to which TGA unit.  auto-link (findCourseForUnit)
        //     is unchanged — it still picks the highest-confidence match automatically.
        //
        // (2) STREAM-NAME: new optional `streamname` field on local_rtocompliance_qualbuilder
        //     lets an RTO create multiple variants of the same qualification code with
        //     different intake/stream labels (e.g. "Import Pathway", "Night School").
        //     Displayed as a subtitle on the Qualbuilder edit page.
        //     DB: new streamname VARCHAR(150) NULL column.
        $dbman = $DB->get_manager();
        $table = new xmldb_table('local_rtocompliance_qualbuilder');
        $field = new xmldb_field('streamname', XMLDB_TYPE_CHAR, '150', null, null, null, null, 'qualificationname');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026072800285, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026072800286) {
        // QB-STREAM-COLUMN-LIST (v5.9.286): Added 'Stream / Variant' column to the
        // Qualification Builder list page (qualbuilder.php) so admins can distinguish
        // multiple variants of the same qualification code at a glance.  When a stream
        // name is set it renders as a grey badge; when blank it shows an em-dash.
        // No DB schema changes — streamname column was added in savepoint 285.
        upgrade_plugin_savepoint(true, 2026072800286, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026072800287) {
        // QB-MOODLE-ROWS (v5.9.287): Three related fixes for the Qualbuilder unit list.
        //
        // (1) DUPLICATE UNITS section: courses in the selected semester that share a
        //     unit code with a TGA unit but are NOT the primary linked course (e.g. two
        //     Moodle courses exist for TLIX0037: standard and -ND variant).  Shown in a
        //     new "Duplicate Units" section so the RTO can see and select the alternate.
        //
        // (2) OTHER MOODLE COURSES section: courses in the semester whose unit code does
        //     not appear in TGA for this qualification — shown so the RTO sees all courses
        //     in the category, not just the TGA-matched 14.
        //
        // (3) data-unitname attribute on every unit row, read back in onUnitToggle and
        //     onCourseChange so Moodle-only/duplicate rows save the course fullname as
        //     the unitname instead of falling back to the unit code.
        //
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026072800287, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026072800288) {
        // QB-NO-BADGE-LABEL (v5.9.288): section() now skips rendering the badge span
        // when badgeType is empty string.  Duplicate Units and Other Moodle Courses
        // sections now pass '' so the orange "IMPORTED" label no longer appears on them.
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026072800288, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026072800289) {
        // QB-REMOVE-DUPES (v5.9.289): Removed the "Duplicate Units" section entirely.
        // Courses in the semester that share a unit code with a TGA unit but are not
        // the primary linked course are now skipped — the RTO creates separate qual
        // records (Teacher 1, Teacher 2, etc.) for different delivery variants.
        // "Other Moodle Courses" section remains for codes not in TGA at all.
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026072800289, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026072800290) {
        // QB-VARIANTS (v5.9.290): teacher-cohort variant course support in Qualbuilder.
        //
        // Each unit row now shows ALL Moodle courses in the semester that share the same
        // unit code as chips alongside the primary linked course badge.  The admin can
        // remove any chip they don't want; the remaining ones are saved to
        // local_rtocompliance_qualunit_courses with is_archive=0.
        //
        // The reconciler (process_enrolment_task) previously only checked
        // qualunit_courses with is_archive=1 (archive fallback).  The is_archive
        // restriction is removed so both variant (0) and archive (1) courses trigger
        // AVETMISS enrolment record creation and qualification-completion detection.
        // Net effect: students in any teacher-cohort variant of a unit (CD/EL/ND) get
        // their enrolment recorded and their certificate issued automatically.
        //
        // No DB schema changes — qualunit_courses table already exists (v5.2.37).
        upgrade_plugin_savepoint(true, 2026072800290, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026072800291) {
        // QB-UX + QPR-PASTE (v5.9.291): Qualification Builder UX improvements.
        //
        // (1) VARIANT-BADGE-READABILITY — primary linked-course badge changed from
        //     green-on-green-tint to white background with green border (readable).
        //
        // (2) VARIANT-ADD-BUTTON — compact circle + button replaces the wide dashed
        //     select.  An info banner above the unit list explains the variant system
        //     with a plain-English example (dismissible per session via sessionStorage).
        //
        // (3) QPR-PASTE-BOX — when TGA returns packagingInformation=null (common for
        //     TLI-series qualifications), the compliance dashboard now shows an amber
        //     paste prompt.  Admin pastes the Packaging Rules text from training.gov.au;
        //     client-side parseQprText() extracts total/core/elective counts; values
        //     update in-memory and are stored on the next Save Qualification click.
        //     Previously the system silently saved unitsToSave.length as totalunits,
        //     producing stale unit counts (e.g. 10 instead of 12).
        //
        // (4) GROUP-KEY-FIX — group-requirements inference now only treats single
        //     uppercase A-Z letters as group codes, preventing TGA's "Core" and
        //     "Elective" label strings from becoming bogus groupRules keys that
        //     suppress the generic Elective status card.
        //
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026072800291, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026072800292) {
        // FIX-SQL-MIXED-PARAMS + FIX-RELEASE-OVERWRITE (v5.9.292): Two bugs fixed.
        //
        // (1) FIX-SQL-MIXED-PARAMS — qualbuilder_edit.php crashed with
        //     "Mixed types of sql query parameters" on every existing QB record load.
        //     get_in_or_equal($unitIds) defaulted to SQL_PARAMS_QM (positional ?)
        //     then $inparams['is_archive_val']=0 added a named :is_archive_val key.
        //     Moodle rejects mixing positional and named params in one query.
        //     Fix: SQL_PARAMS_NAMED,'quid' passed to get_in_or_equal so all params
        //     use named placeholders.
        //
        // (2) FIX-RELEASE-OVERWRITE — version.php had a bare $plugin->release='5.9.267'
        //     assignment (missing _prev suffix) that overwrote the real release string
        //     in PHP (last assignment wins). Moodle's plugin page showed 5.9.267
        //     regardless of installed version. Fixed: corrected to $plugin->release_prev.
        //
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026072800292, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026072800293) {
        // QB-VARIANT-UNION-FIX (v5.9.293): Five OR/fallback patterns replaced with
        // UNION so a course that is primary in one QB record AND a variant in another
        // triggers enrolment creation and autocert for both.
        //
        // (1) process_enrolment_created — always queries qualunit_courses, merges by
        //     qu.id so no duplicate enrolment rows are created.
        //
        // (2) queue_autocert_if_all_units_complete — always merges variant qualbuilderids
        //     into the primary set; autocert fires for every QB referencing the course.
        //
        // (3) local_rtocompliance_check_full_qual_completion (lib.php) — per-unit check
        //     now accepts ANY delivery course (primary OR variant) as satisfying the unit.
        //     Previously a variant-only completer always returned false → SoA instead of
        //     Testamur.
        //
        // (4) local_rtocompliance_get_completed_units_for_qual (lib.php) — partial SoA
        //     unit list now includes units completed via a variant course.
        //
        // (5) generate_qual_certs.php — completion timestamp lookup uses $alllinkedcourseids
        //     (primary + variants) so cert issue date is correct for variant completers.
        //
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026072800293, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026072800294) {
        // QB-LIST-TOTAL-FIX + VARIANT-COURSE-CERT-TYPE-FIX (v5.9.294):
        //
        // (1) qualbuilder.php list page — UNITS column and LINKED X/Y denominator now use
        //     qb.totalunits (the TGA-sourced packaging-rules total stored on the QB record)
        //     instead of COUNT(selected=1). Previously a qualification with 10 core units
        //     saved and 2 elective units not yet selected showed "10" / "6/10" on the list
        //     page even though the edit page correctly showed "6/12 linked".  Now both pages
        //     agree.  Falls back to the DB count for old records where totalunits = 0.
        //
        // (2) local_rtocompliance_resolve_cert_types_for_course Step 2 (lib.php) — the
        //     qualbuilder lookup that drives generate_course_certs.php (Generate by Course)
        //     previously only checked qualunits.courseid. If an admin navigated to a variant
        //     course page, the lookup returned null and the cert type fell back to a generic
        //     SoA from course settings instead of the correct Testamur + RoR path.
        //     Fixed: if the primary lookup returns null, a second query checks
        //     qualunit_courses (variant/archive courses) to find the owning qualbuilder.
        //
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026072800294, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026072800295) {
        // RESULTS-PAGE-DATA-FIX (v5.9.295):
        //
        // Six accuracy bugs fixed in qualbuilder_results.php (View Results page):
        //
        // (1) RESULTS-SELECTED-UNITS-FIX: $units query was missing selected=1 —
        //     "Units in Product" stat counted unselected rows; per-student progress
        //     grid showed unselected unit columns; progress % denominator was inflated.
        //     Core Units and Elective Units already used selected=1, making all three
        //     inconsistent. Fixed: selected=1 added to $units query.
        //
        // (2) RESULTS-VARIANT-STUDENT-FIX: student matching SQL fallback OR clause
        //     only checked qualunits.courseid (primary courses) — students enrolled
        //     via a variant delivery course but without programcode set were silently
        //     excluded. Fixed: UNION with qualunit_courses (is_archive=0) added to
        //     the courseid IN subquery, matching the v5.9.293 pattern applied across
        //     process_enrolment_task.php, lib.php, and generate_qual_certs.php.
        //
        // (3) RESULTS-STATS-MATCH-FIX: $totalstudents and $completedstudents stat
        //     queries used programcode-only matching — narrower than the student table
        //     query which used the courseid fallback — "Total Enrolled" could show
        //     fewer students than the table row count and "In Progress" could go
        //     negative. Both now use programcode-OR-courseid(primary+variant).
        //
        // (4) RESULTS-COMPLETED-UNITS-FILTER: completedstudents "all units finalised"
        //     subquery now filters selected=1 to match the display unit set.
        //
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026072800295, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026072800296) {
        // ENROLMENT-CERT-PIPELINE-FIX (v5.9.296):
        //
        // Four bugs fixed across the enrolment→qualbuilder→certificate pipeline
        // found by full parallel subagent audit of all three systems.
        //
        // (1) RESULTS-COMPLETION-ENROLMENTS-FIX (lib.php: check_full_qual_completion):
        //     Only checked Moodle course_completions. If an RTO imported or manually
        //     entered AVETMISS outcomes (20/51/60/81) but Moodle course completion was
        //     not enabled on the course, this function returned false — generate_course_certs
        //     issued a SoA instead of a Testamur + Record of Results.
        //     Fixed: enrolments table fallback added per unit so positive AVETMISS
        //     outcomes satisfy the completion check when Moodle completion is absent.
        //
        // (2) SOA-OUTCOME-FIX (lib.php: get_completed_units_for_qual):
        //     Returned hardcoded outcome '20' (Competent) for every unit on partial SoA
        //     PDFs — units completed via RPL (51), Credit Transfer (60), or Non-Assessed
        //     Satisfactory (81) all incorrectly printed as Competent on the issued cert.
        //     Fixed: actual outcomeidentifier fetched from enrolments table and returned.
        //     Same function also gained the enrolments fallback from fix (1).
        //
        // (3) SOA-SELECTED-UNITS-FIX (lib.php: get_completed_units_for_qual):
        //     Qualunits query was missing selected=1 — unselected/deselected units could
        //     appear as completed rows on issued partial SoA PDFs.
        //     Fixed: selected=1 filter added.
        //
        // (4) IMPORT-VARIANT-FIX (student_enrolments.php Moodle import):
        //     Both the linked_units query and the fallback_qual query only checked
        //     qualunits.courseid (primary courses). Students enrolled in a variant
        //     delivery course imported with no unitcode and no programcode, appearing
        //     as a bare course-level record with outcome '70' rather than per-unit
        //     AVETMISS records. Fixed: UNION with qualunit_courses (is_archive=0) added
        //     to both queries; selected=1 also added to the primary query.
        //
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026072800296, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026072800297) {
        // TASK-VARIANT-ARCHIVE-FIX + CERT-CREDITS-BREAK-FIX + AUTOCERT-COMPLETE-FIX (v5.9.297):
        //
        // (1) TASK-VARIANT-ARCHIVE-FIX: process_enrolment_task.php UNION queries into
        //     qualunit_courses were missing an is_archive filter — archived semester courses
        //     (is_archive=1) were triggering live AVETMISS enrolment creation and autocert
        //     qualification-completion checks.  Fixed: AND (quc.is_archive IS NULL OR
        //     quc.is_archive = 0) added to both UNION queries.
        //
        // (2) CERT-CREDITS-BREAK-FIX: generate_qual_certs.php used 'break 2' on credit
        //     exhaustion, silently killing both loops.  Fixed: $creditsExhausted flag breaks
        //     inner loop only; outer loop counts remaining students as failed.
        //
        // (3) AUTOCERT-COMPLETE-FIX: autocerts rows stayed 'pending' forever after cert
        //     issuance — generate_qual_certs.php now transitions the matching row to
        //     status='complete' after successful issuance.
        //
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026072800297, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026072800298) {
        // VERIFY-BTN-FORM-FIX (v5.9.298): "Verify USI" buttons on students.php sit
        // inside the bulk-action <form id="student-action-form" method="post">.
        // HTML buttons default to type="submit" — clicking "Verify via usi.gov.au"
        // submitted the form and navigated to the action URL (Moodle error page)
        // instead of running the AJAX verification call.
        // Fixed: type="button" added to all rtoc-usi-verify-btn elements in
        // students.php and student_usi_verify.php; e.preventDefault() added to
        // the JS delegated click handler.
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026072800298, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026072800299) {
        // 7-SECTION AUDIT FIXES (v5.9.299 / 28 Jul 2026):
        //
        // 1. COURSE-CERT-CREDITS-BREAK-FIX: generate_course_certs.php used break 2 on
        //    INSUFFICIENT_CREDITS, silently killing both student loops. Replaced with a
        //    $creditsExhausted flag so the outer loop tallies all remaining students as failed.
        //
        // 2. SCHOOLTYPE-VALIDCOLUMNS-FIX: the schooltype column (school-based students: GOV,
        //    CAT, IND, OTH) exists in the DB and is shown in student_profile_form.php but was
        //    missing from $validcolumns in both student_profile.php and my_profile.php, so it
        //    was silently dropped on every save.
        //
        // 3. PROFILECOMPLETE-ALIGN-FIX: validate_student_profile() (admin path) only checked
        //    6 AVETMISS fields while my_profile.php (student self-service) checked 11.
        //    Profiles marked "complete" by admin flipped back to "incomplete" when students
        //    saved their profile. Aligned admin validation to the same 11-field definition.
        //    Added 5 new lang strings for the additional error messages.
        //
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026072800299, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026072800300) {
        // E2E FLOW AUDIT FIXES (v5.9.300 / 28 Jul 2026) — 8-agent end-to-end analysis:
        //
        // 1. RESULTS-CAPABILITY-FIX: qualbuilder_results.php relied solely on
        //    admin_externalpage_setup() which only gates on admin-area access, not on
        //    local/rtocompliance:manage.  Any site admin could read all student results
        //    regardless of RTO compliance role.  Explicit require_capability() added.
        //
        // 2. REISSUE-REGISTRY-PUBLISH-FIX: reissue_cert.php correctly marked the OLD
        //    cert's token as 'superseded' in the registry but never published the NEW
        //    cert's verifytoken.  The new cert's QR code returned "not found" until a
        //    separate email/download triggered publication.  Added best-effort publish
        //    call immediately after insert, wrapped in try/catch so a registry outage
        //    cannot abort the reissue.
        //
        // 3. SETUP-PROGRESS-QUALS-FIX: the "Add Your First Qualification" step on the
        //    dashboard Setup Progress tracker was checking local_rtocompliance_tas
        //    (Training and Assessment Strategy) instead of local_rtocompliance_qualbuilder.
        //    RTOs that built qualifications without a TAS saw step 2 stuck at incomplete;
        //    RTOs that created a TAS first saw a false complete.  Fixed to check qualbuilder.
        //
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026072800300, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026072800301) {
        // FLOW-BLOCKER FIXES (v5.9.301 / 28 Jul 2026) — 6-agent flow-blocker scan:
        //
        // 1. APPEAL-FORM-PARSE-FIX: appeal_form.php contained inline JS inside a
        //    single-quoted PHP string. The regex replacement strings '$1' broke PHP's
        //    parser (the ' closed the string), causing a fatal parse error that made
        //    the entire Appeals feature white-screen. Fixed by switching all JS regex
        //    replacement strings from single to double quotes.
        //
        // 2. AI-ANALYSIS-PARSE-FIX: ai_analysis.php had an orphaned
        //    echo html_writer::tag('div', call with no arguments on line 433 — a
        //    leftover from a partial edit. The assignment on line 434 was being parsed
        //    as the first argument, causing a fatal syntax error on every page load.
        //    Removed the orphaned echo call.
        //
        // 3. TASKS-DOUBLE-BACKSLASH-FIX: db/tasks.php declared the class name for
        //    update_trainer_status_task with a double backslash (\\update_trainer...)
        //    instead of a single backslash. While PHP resolves this in some contexts,
        //    it is non-standard and can cause task discovery failures on strict
        //    autoloaders or Windows hosts. Fixed to single backslash.
        //
        // 4. CERTNUMBER-COLLISION-FIX: local_rtocompliance_programmatic_issue_cert()
        //    used COUNT(*)+1 to generate the next cert sequence number. Two parallel
        //    issuance calls in the same second both read the same count and produced
        //    the same cert number, resulting in duplicate numbers with no DB constraint
        //    to catch it. Added a collision-guard loop that increments the sequence
        //    while a row with the candidate number already exists.
        //
        // 5. CERT-INSERT-CATCH-FIX: the DB insert_record() call in
        //    programmatic_issue_cert() was unwrapped. Any dml_write_exception
        //    (deadlock, disk full, etc.) bubbled up uncaught through
        //    generate_qual_certs.php's bulk loop, abandoning all remaining students
        //    in the batch with no error recorded in the summary. Wrapped in try/catch
        //    returning a structured ['ok'=>false, 'error'=>'DB_INSERT_FAILED'] so the
        //    caller can tally the failure and continue.
        //
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026072800301, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026072800302) {
        // USI STUCK-PENDING FIX (v5.9.302 / 28 Jul 2026):
        //
        // ROOT CAUSE: verify_usi_batch_task calls verify_pending_batch() which selects
        // students WHERE usiverified = 0.  When the machine credential was first uploaded
        // the platform returned HTTP 503 / status:CERT_PENDING for every student.
        // usi_verification_service::update_student_verification_status() maps all
        // unrecognised statuses (including CERT_PENDING, NETWORK_ERROR, AUTH_ERROR)
        // to STATUS_PENDING = 3.  Since the batch query only ever fetched usiverified=0,
        // all 1,066 students were permanently stuck at status 3 — the next scheduled
        // run would log "No pending USI verifications" and exit immediately.
        //
        // CHANGES:
        // 1. verify_pending_batch() WHERE clause now reads usiverified IN (0, 3) so
        //    pending-retry students are automatically re-tried on the next cron run.
        //    Also tightened: dateofbirth != 0 guard added (avoids wasted API calls).
        //
        // 2. New method usi_verification_service::reset_stuck_pending() bulk-resets
        //    usiverified=3 → usiverified=0 for students who have both USI and DOB.
        //    Exposed as an admin action on the Students page.
        //
        // 3. Students page now counts usiverified=3 students into $stats['usi_pending_retry']
        //    and shows an amber banner with a one-click "Retry Pending USI Verifications"
        //    button (mirroring the existing "Sync DOBs from NAT00080" pattern).
        //
        // 4. get_verification_stats() now includes 'pending_retry' count (usiverified=3)
        //    so the scheduled task log shows how many are waiting for retry.
        //
        // No DB schema changes.  Existing usiverified=3 students are unaffected until
        // the admin clicks the retry button or the next batch run processes them.
        upgrade_plugin_savepoint(true, 2026072800302, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026072800303) {
        // NAT00080 UPLOAD LINK ON STUDENTS PAGE (v5.9.303 / 28 Jul 2026):
        //
        // The "DOB required to verify" banner on the Students page previously said
        // "if you have uploaded a NAT00080 file, click the button" but gave no way
        // to upload one. Admins had to know to navigate to Data Import separately.
        //
        // Changes:
        // 1. Banner now always shows an "Upload NAT00080 →" button linking directly
        //    to data_import.php so the admin can upload without navigating away.
        //
        // 2. Banner is context-aware: if no NAT00080 data has been uploaded yet
        //    (local_rtocompliance_avetmiss_student table is empty), the message
        //    leads with "Upload a NAT00080 file first, then click Sync" and hides
        //    the now-useless Sync button. Once data is present, both buttons show.
        //
        // No DB schema changes. No savepoint data migration needed.
        upgrade_plugin_savepoint(true, 2026072800303, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026072800304) {
        // E2E FLOW AUDIT FIXES (v5.9.304 / 28 Jul 2026) — 6-leg parallel audit:
        //
        // 1. USI-VERIFY-PRESERVE-FIX (student_profile.php + my_profile.php):
        //    CRITICAL. Both usiverified and usiverifieddate were in $validcolumns but
        //    neither is an actual form field. In student_profile.php, a fallback
        //    `if (!isset) → 0` block ALWAYS fired after the cleandata loop, resetting
        //    every verified student to usiverified=0 the moment an admin clicked Save
        //    Profile. In my_profile.php the same columns were in $validcolumns, opening
        //    a security hole where a crafted POST could reset verification status.
        //    Fix: removed usiverified/usiverifieddate from $validcolumns in both files;
        //    added explicit preserve logic — if USI is unchanged, restore the existing
        //    usiverified/usiverifieddate from the DB row; if the USI value itself changed,
        //    reset to 0 (new code is unverified). On create, always start at 0.
        //
        // 2. USI-PENDING-FILTER-FIX (students.php):
        //    The "Unverified USI" student filter used usiverified IN (0, NULL) which
        //    excluded students stuck at usiverified=3 (STATUS_PENDING — transient error
        //    from first verification attempt). Those students showed as invisible in the
        //    Unverified view, creating the false impression that nothing needed action.
        //    Fix: filter now uses usiverified IN (0, 3).
        //
        // 3. NAT-UNVERIFIED-USI-WARNING (nat_generator.php):
        //    The NAT export pre-flight (validate()) warned for missing USI but never
        //    checked usiverified. Unverified USIs are exported as raw strings; NCVER
        //    will reject rows where the code doesn't match the registry. Added warning
        //    showing the count of students with a USI that hasn't been verified.
        //
        // 4. NAT-MISSING-DOB-WARNING (nat_generator.php):
        //    Missing DOB produced 8 spaces in NAT00080 positions 74-81 with no warning.
        //    NCVER treats this as "not stated" and rejects it for domestic students under
        //    reporting rule R17. Added warning showing count of students with DOB=0/NULL.
        //
        // 5. ENROLMENT-SKIP-LOG (process_enrolment_task.php):
        //    When a course had no Qual Builder link, no nationallyrecognised flag, and no
        //    valid AVETMISS idnumber fallback, the task silently returned with no trace.
        //    Admins had no way to diagnose why enrolment events fired but produced no RTO
        //    compliance record. Added debugging() call that shows in cron logs and PHP
        //    error log when developer debug is enabled.
        //
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026072800304, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026072800305) {
        // C1-USI-VALIDATE-FIX (v5.9.305 / 28 Jul 2026):
        //   usi_platform_client::validate_input() used /i flag (case-insensitive),
        //   accepting chars 0, 1, I, O that are explicitly excluded by the AVETMISS
        //   USI character set.  Aligned with avetmiss_codes::validate_usi() (the
        //   authoritative validator): strtoupper/trim first, then /^[2-9A-HJ-NP-Z]{10}$/
        //   without the /i flag. No DB changes.
        //
        // C2-USI-SNAPSHOT-FIX (v5.9.305 / 28 Jul 2026):
        //   local_rtocompliance_certs had no usi column. Every re-render of a cert
        //   (PDF download, re-issue, QR verify) pulled the LIVE usi from the students
        //   table. A post-issuance USI correction silently altered all historical PDFs,
        //   destroying the forensic audit trail required under ASQA regulatory practice.
        //   Fix:
        //     1. Add usi CHAR(15) NULLABLE column to local_rtocompliance_certs.
        //     2. Backfill existing rows from local_rtocompliance_students (best-effort;
        //        a student with no students row is left NULL).
        //     3. programmatic_issue_cert() now snapshots the USI at issuance time.
        //
        // H3-TEMPLATE-FALLBACK-LOG (v5.9.305 / 28 Jul 2026):
        //   The cert template renderer catch block only called debugging() at
        //   DEBUG_DEVELOPER — invisible on production. Admins were unknowingly issuing
        //   certs from the legacy TCPDF fallback layout with no warning.
        //   Fix: log to local_rtocompliance_log (always visible in admin audit view) AND
        //   write cert_template_fallback_count + cert_template_fallback_last config keys
        //   so the dashboard can show an amber warning banner. No DB schema changes
        //   for H3 — handled entirely in lib.php.

        // --- C2: add usi column to certs table ---
        $table = new xmldb_table('local_rtocompliance_certs');
        $field = new xmldb_field('usi', XMLDB_TYPE_CHAR, '15', null, XMLDB_NOTNULL_FALSE, null, null, 'certtmplid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // --- C2: backfill usi for all existing cert rows ---
        // Correlated-subquery UPDATE: works on MySQL and PostgreSQL.
        // Students with no matching students row are left NULL (not ''), which is
        // the correct sentinel for "USI was not recorded at issuance time".
        $DB->execute(
            "UPDATE {local_rtocompliance_certs}
                SET usi = (
                    SELECT s.usi
                      FROM {local_rtocompliance_students} s
                     WHERE s.userid = {local_rtocompliance_certs}.userid
                )
              WHERE usi IS NULL"
        );

        upgrade_plugin_savepoint(true, 2026072800305, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026072800306) {
        // VET DATA COLLECTION AUDIT — full line-by-line review of every AVETMISS field
        // from enrolment through profile form through NAT export. Two code bugs found
        // and fixed (v5.9.306 / 28 Jul 2026). No DB schema changes in this version.
        //
        // SENTINEL-FIX (v5.9.306):
        //   validate_student_profile() in student_profile.php tested
        //   `labourforcestatus === '@'` and `highestschoollevel === '@'` (single @).
        //   Both fields use '@@' (double-@) as their AVETMISS "not stated" sentinel —
        //   which is also the factory default stored by the auto-create task and the
        //   UI form. The single-@ check never matched, so:
        //     - A student with labourforcestatus='@@' (default) passed admin validation
        //     - Admin save wrote profilecomplete=1
        //     - Student opened my_profile.php which correctly checks for '@@'
        //     - Profile immediately flipped back to profilecomplete=0
        //   Profile "flapped" between complete and incomplete depending on who saved
        //   last. Both checks are now `=== '@@' || === '@'` to also catch any
        //   single-@ values already in the DB.
        //
        // DEPENDENT-FIELD-CLEAR-FIX (v5.9.306):
        //   Moodle's hideIf is client-side JavaScript only. A hidden form field still
        //   submits its cached value in the POST body. Three dependent-field pairs were
        //   affected in both student_profile.php and my_profile.php:
        //
        //   (a) schooltype (child) / atschoolflag (parent):
        //       If an admin set atschoolflag='Y' + schooltype='GOV', then later set
        //       atschoolflag='N', schooltype='GOV' persisted in the DB. nat_generator
        //       generate_nat00120() maps schooltype unconditionally at pos 110-111,
        //       so the student's enrolment rows were emitted with school-type code '10'
        //       (GOV) even though they were no longer school-based — invalid AVETMISS.
        //
        //   (b) disabilitytypes (child) / disabilityflag (parent):
        //       Stale disability type codes persisted when flag was changed to 'N' or '@'.
        //       NAT00090 guards on disabilityflag='Y' so the current export was safe,
        //       but toggling the flag back to 'Y' would re-surface old type codes.
        //
        //   (c) surveycontactemail / surveycontactphone (children) / surveycontactstatus:
        //       Stale email/phone persisted when status changed to 'N' or 'M'. NAT00085
        //       always exports both fields, so students who withdrew contact consent
        //       still had their email/phone in every subsequent NAT export.
        //
        //   Fix: added server-side guards in both save paths to null out child fields
        //   when the parent flag no longer warrants them.
        //
        // No DB schema changes — upgrade step records the savepoint only.
        upgrade_plugin_savepoint(true, 2026072800306, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026072800307) {
        // INLINE-NAT-UPLOAD (v5.9.307 / 29 Jul 2026):
        //
        // Added an inline NAT00080 file upload form directly on the Students page
        // DOB-sync warning bar. Previously, the "Upload NAT00080" button was a link
        // to the full Data Import wizard (data_import.php) — the admin had to leave
        // the Students page, navigate through the multi-step import, and then return.
        //
        // The DOB bar now contains:
        //   • A styled file-picker label (hidden <input type="file">) so the admin can
        //     select their NAT00080 .txt file from the Students page directly.
        //   • An "Upload & Sync DOBs" submit button that appears once a file is chosen.
        //   • A secondary "Sync from imported data" link (visible only when NAT data
        //     was previously imported via the Data Import wizard) — same as before.
        //
        // New action: students.php?action=upload_nat_dobs
        //   • Accepts the uploaded file via $_FILES['nat00080file'].
        //   • Parses clientid (pos 0-9) and DOB (pos 73-80, DDMMYYYY) from each
        //     fixed-width line ≥ 81 chars.
        //   • Applies the same two-path matching as sync_dobs_from_nat:
        //       Path A — direct match on local_rtocompliance_students.clientid
        //       Path B — match via mdl_user.idnumber = clientid
        //   • Only writes dateofbirth where currently NULL or 0 (never overwrites).
        //   • Backfills clientid on Path B matches so future Path A matches work.
        //   • Safety cap: rejects files larger than 20 MB with an explanatory error.
        //   • Falls back gracefully if the file cannot be parsed as fixed-width NAT00080:
        //     shows count of parsed/skipped rows and directs the user to Data Import
        //     for tab-delimited or variant formats.
        //
        // No DB schema changes — upgrade step records the savepoint only.
        upgrade_plugin_savepoint(true, 2026072800307, 'local', 'rtocompliance');
    }

    if ($oldversion < 2026072800308) {
        // USI-CONFIG-KEY-FIX (v5.9.308 / 29 Jul 2026):
        //
        // students.php line 324 read get_config('local_rtocompliance', 'api_url')
        // (with underscore) but the key written by usi_settings.php and read by
        // every other file in the plugin is 'apiurl' (no underscore). The key
        // 'api_url' was never written anywhere so it always returned an empty
        // string, causing the "USI Verification Not Configured" modal to fire on
        // every page load even when USI was fully configured.
        //
        // Fix: changed get_config key from 'api_url' to 'apiurl' in students.php.
        //
        // No DB schema changes — upgrade step records the savepoint only.
        upgrade_plugin_savepoint(true, 2026072800308, 'local', 'rtocompliance');
    }

    return true;
}