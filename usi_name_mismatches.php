<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Read-only comparison of Moodle account names and RTO Compliance profile names.
 *
 * @package    local_rtocompliance
 * @copyright  2026 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/lib.php');

use local_rtocompliance\local\student_name_comparison;

admin_externalpage_setup('local_rtocompliance_students');
require_login();

$context = context_system::instance();
$canview = has_capability('local/rtocompliance:viewall', $context)
    || has_capability('local/rtocompliance:manage', $context);
if (!$canview) {
    throw new moodle_exception('nopermissions', 'error', '', get_string('student_records', 'local_rtocompliance'));
}
$canedit = has_capability('local/rtocompliance:manage', $context);
$page = max(0, optional_param('page', 0, PARAM_INT));
$perpage = 100;

$PAGE->set_url(new moodle_url('/local/rtocompliance/usi_name_mismatches.php', ['page' => $page]));
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('name_compare_title', 'local_rtocompliance'));
$PAGE->set_heading(get_string('name_compare_title', 'local_rtocompliance'));
$PAGE->navbar->add(
    get_string('student_records', 'local_rtocompliance'),
    new moodle_url('/local/rtocompliance/students.php')
);
$PAGE->navbar->add(get_string('name_compare_title', 'local_rtocompliance'));

// Match the Student Records roster's exclusions: site administrators, staff roles
// and users registered as RTO trainers are not learners in this report.
$siteadminlist = '0';
if (!empty($CFG->siteadmins)) {
    $siteadminids = array_filter(array_map('intval', explode(',', $CFG->siteadmins)));
    if ($siteadminids) {
        $siteadminlist = implode(',', $siteadminids);
    }
}

$fromsql = " FROM {user} u
        LEFT JOIN {local_rtocompliance_students} s ON s.userid = u.id
        LEFT JOIN (
            SELECT DISTINCT ra.userid
              FROM {role_assignments} ra
              JOIN {role} r ON r.id = ra.roleid
             WHERE r.shortname IN ('editingteacher','teacher','manager','coursecreator',
                                   'trainer','assessor','trainerassessor')
                OR r.archetype IN ('editingteacher','teacher','manager')
        ) staff ON staff.userid = u.id
        LEFT JOIN (
            SELECT DISTINCT userid FROM {local_rtocompliance_trainers}
        ) rtoc_trainer ON rtoc_trainer.userid = u.id";
$wheresql = "u.deleted = 0
        AND u.id NOT IN ($siteadminlist)
        AND staff.userid IS NULL
        AND rtoc_trainer.userid IS NULL
        AND " . student_name_comparison::sql_mismatch_condition();

$total = $DB->count_records_sql(
    'SELECT COUNT(DISTINCT u.id)' . $fromsql . ' WHERE ' . $wheresql
);
$rows = $DB->get_records_sql(
    "SELECT u.id AS userid,
            u.firstname AS moodlefirstname,
            u.lastname AS moodlelastname,
            u.suspended,
            s.firstname AS rtofirstname,
            s.lastname AS rtolastname
       " . $fromsql . "
      WHERE " . $wheresql . "
   ORDER BY u.lastname ASC, u.firstname ASC, u.id ASC",
    null,
    $page * $perpage,
    $perpage
);

echo $OUTPUT->header();
echo local_rtocompliance_render_nav_header(
    get_string('name_compare_title', 'local_rtocompliance'),
    null,
    null,
    'students'
);
echo html_writer::div(
    get_string('name_compare_intro', 'local_rtocompliance'),
    'alert alert-info'
);
echo html_writer::tag(
    'p',
    get_string('name_compare_count', 'local_rtocompliance', $total),
    ['class' => 'font-weight-bold']
);
echo html_writer::link(
    new moodle_url('/local/rtocompliance/students.php'),
    get_string('student_records', 'local_rtocompliance'),
    ['class' => 'btn btn-secondary mb-3']
);

if (!$total) {
    echo $OUTPUT->notification(
        get_string('name_compare_none', 'local_rtocompliance'),
        \core\output\notification::NOTIFY_SUCCESS
    );
} else {
    $table = new html_table();
    $table->head = [
        get_string('name'),
        get_string('name_compare_moodle', 'local_rtocompliance'),
        get_string('name_compare_rto', 'local_rtocompliance'),
        get_string('name_compare_status', 'local_rtocompliance'),
        get_string('actions'),
    ];
    $table->attributes['class'] = 'generaltable';

    foreach ($rows as $row) {
        $comparison = student_name_comparison::compare_values(
            $row->rtofirstname,
            $row->rtolastname,
            $row->moodlefirstname,
            $row->moodlelastname
        );
        $displayname = trim($row->moodlelastname . ', ' . $row->moodlefirstname, ' ,');
        $moodlename = trim($comparison['moodle_firstname'] . ' ' . $comparison['moodle_lastname']);
        $rtoname = trim($comparison['rto_firstname'] . ' ' . $comparison['rto_lastname']);
        $moodlename = $moodlename === '' ? '(blank)' : $moodlename;
        $rtoname = $rtoname === '' ? '(blank)' : $rtoname;

        $reviewfields = [];
        if (!$comparison['firstname_matches']) {
            $reviewfields[] = get_string('rto_firstname', 'local_rtocompliance');
        }
        if (!$comparison['lastname_matches']) {
            $reviewfields[] = get_string('rto_lastname', 'local_rtocompliance');
        }

        $action = '';
        if ($canedit) {
            $editurl = new moodle_url(
                '/local/rtocompliance/student_profile.php',
                ['userid' => $row->userid],
                'id_firstname'
            );
            $action = html_writer::link(
                $editurl,
                get_string('name_compare_edit', 'local_rtocompliance'),
                ['class' => 'btn btn-sm btn-primary']
            );
        }

        $status = !empty($row->suspended)
            ? get_string('suspended')
            : get_string('active');
        $table->data[] = [
            s($displayname),
            s($moodlename),
            s($rtoname),
            s(implode(', ', $reviewfields) . ' — ' . $status),
            $action,
        ];
    }

    echo html_writer::table($table);
    echo $OUTPUT->paging_bar(
        $total,
        $page,
        $perpage,
        new moodle_url('/local/rtocompliance/usi_name_mismatches.php')
    );
}

echo $OUTPUT->footer();
