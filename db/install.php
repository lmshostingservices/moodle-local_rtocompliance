<?php
defined('MOODLE_INTERNAL') || die();

function xmldb_local_rtocompliance_install() {
    global $CFG, $DB;

    require_once($CFG->dirroot . '/local/rtocompliance/lib.php');

    // Create default regulatory deadlines.
    // Note: AVETMISS profile fields are no longer created in Moodle's user profile system.
    // All AVETMISS data is stored exclusively in the local_rtocompliance_students table.
    // This prevents admins/teachers from seeing AVETMISS prompts on their profile page.
    local_rtocompliance_create_default_deadlines();

    return true;
}

function local_rtocompliance_create_default_deadlines() {
    global $DB;

    $currentyear = date('Y');
    $nextyear = $currentyear + 1;

    $deadlines = [
        [
            'deadlinetype' => 'tva',
            'title' => 'Total VET Activity (TVA) Submission ' . $nextyear,
            'description' => 'Submit AVETMISS data to NCVER for the previous calendar year.',
            'duedate' => strtotime("$nextyear-02-28"),
            'reminderdays' => 30,
            'recurring' => 1,
            'recurringperiod' => 'yearly',
        ],
        [
            'deadlinetype' => 'qi',
            'title' => 'Quality Indicator Data Submission ' . $nextyear,
            'description' => 'Submit learner and employer satisfaction survey data to NCVER.',
            'duedate' => strtotime("$nextyear-06-30"),
            'reminderdays' => 30,
            'recurring' => 1,
            'recurringperiod' => 'yearly',
        ],
    ];

    foreach ($deadlines as $deadline) {
        $deadline['status'] = 'pending';
        $deadline['timecreated'] = time();
        $deadline['timemodified'] = time();
        $DB->insert_record('local_rtocompliance_deadlines', (object)$deadline);
    }
}
