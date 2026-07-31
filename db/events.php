<?php
defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        'eventname' => '\core\event\course_deleted',
        'callback' => 'local_rtocompliance_observer::course_deleted',
        'includefile' => '/local/rtocompliance/classes/observer.php',
        'priority' => 0,
        'internal' => true,
    ],
    [
        'eventname' => '\core\event\\user_enrolment_created',
        'callback' => 'local_rtocompliance_observer::user_enrolment_created',
        'includefile' => '/local/rtocompliance/classes/observer.php',
        'priority' => 0,
        'internal' => true,
    ],
    [
        'eventname' => '\core\event\\user_enrolment_deleted',
        'callback' => 'local_rtocompliance_observer::user_enrolment_deleted',
        'includefile' => '/local/rtocompliance/classes/observer.php',
        'priority' => 0,
        'internal' => true,
    ],
    [
        'eventname' => '\core\event\course_completed',
        'callback' => 'local_rtocompliance_observer::course_completed',
        'includefile' => '/local/rtocompliance/classes/observer.php',
        'priority' => 0,
        'internal' => true,
    ],
    [
        'eventname' => '\core\event\course_module_completion_updated',
        'callback' => 'local_rtocompliance_observer::course_module_completion_updated',
        'includefile' => '/local/rtocompliance/classes/observer.php',
        'priority' => 0,
        'internal' => true,
    ],
    [
        'eventname' => '\core\event\user_graded',
        'callback' => 'local_rtocompliance_observer::user_graded',
        'includefile' => '/local/rtocompliance/classes/observer.php',
        'priority' => 0,
        'internal' => true,
    ],
];