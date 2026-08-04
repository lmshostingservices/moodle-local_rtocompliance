<?php
// require_login() — deliberately omitted: this endpoint uses its own authentication or is not a user-facing web page.
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
 * RTO Compliance Test Fixture Seeder
 * 
 * This CLI script creates comprehensive test data for the RTO Compliance plugin.
 * It generates:
 * - Test users (students, trainers, admins)
 * - Test courses with qualifications and units
 * - Student AVETMISS profiles
 * - Enrolments with various outcomes
 * - Trainer credentials
 * - Sample certificates
 * - Survey responses
 * 
 * Usage: php admin/cli/fixture_seed.php
 * 
 * @package    local_rtocompliance
 * @copyright  2025 AI Grader
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/datalib.php');
require_once($CFG->dirroot . '/user/lib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once(__DIR__ . '/../classes/avetmiss_codes.php');

use local_rtocompliance\avetmiss_codes;

list($options, $unrecognized) = cli_get_params(
    [
        'help' => false,
        'students' => 20,
        'trainers' => 5,
        'courses' => 8,
        'clean' => false,
        'verbose' => false,
    ],
    [
        'h' => 'help',
        's' => 'students',
        't' => 'trainers',
        'c' => 'courses',
        'v' => 'verbose',
    ]
);

if ($options['help']) {
    $help = <<<EOL
RTO Compliance Test Fixture Seeder

This script creates comprehensive test data for the RTO Compliance plugin.

Options:
  -h, --help          Display this help message
  -s, --students=N    Number of test students to create (default: 20)
  -t, --trainers=N    Number of test trainers to create (default: 5)
  -c, --courses=N     Number of test courses to create (default: 8)
  --clean             Remove all test data before creating new fixtures
  -v, --verbose       Show detailed progress output

Example:
  php cli/fixture_seed.php --students=50 --trainers=10 --courses=15

EOL;
    cli_writeln($help);
    exit(0);
}

$verbose = $options['verbose'];

function log_message($message, $is_verbose = false) {
    global $verbose;
    if (!$is_verbose || $verbose) {
        cli_writeln($message);
    }
}

// RTO Configuration
$rtoconfig = [
    'rtocode' => '12345',
    'rtoname' => 'Test Training Institute',
    'abn' => '12345678901',
    'address' => '123 Training Street, Melbourne VIC 3000',
    'phone' => '0398765432',
    'email' => 'admin@testtraining.edu.au',
];

log_message("=== RTO Compliance Test Fixture Seeder ===\n");

// Set RTO configuration
foreach ($rtoconfig as $key => $value) {
    set_config($key, $value, 'local_rtocompliance');
}
log_message("✓ RTO configuration set");

// Clean existing test data if requested
if ($options['clean']) {
    log_message("Cleaning existing test data...");
    
    // Delete test users
    $testusers = $DB->get_records_select('user', "username LIKE 'rtotest_%'");
    foreach ($testusers as $user) {
        delete_user($user);
    }
    
    // Delete test courses
    $testcourses = $DB->get_records_select('course', "shortname LIKE 'RTOTEST-%'");
    foreach ($testcourses as $course) {
        delete_course($course->id, false);
    }
    
    // Clear RTO tables
    $DB->delete_records_select('local_rtocompliance_students', "1=1");
    $DB->delete_records_select('local_rtocompliance_enrolments', "1=1");
    $DB->delete_records_select('local_rtocompliance_trainers', "1=1");
    $DB->delete_records_select('local_rtocompliance_certs', "1=1");
    $DB->delete_records_select('local_rtocompliance_surveys', "1=1");
    $DB->delete_records_select('local_rtocompliance_log', "1=1");
    
    log_message("✓ Existing test data cleaned\n");
}

// ============================================
// TEST QUALIFICATIONS AND UNITS
// ============================================

$qualifications = [
    [
        'code' => 'BSB50420',
        'name' => 'Diploma of Leadership and Management',
        'units' => [
            ['code' => 'BSBCMM511', 'name' => 'Communicate with influence', 'hours' => 60],
            ['code' => 'BSBCRT511', 'name' => 'Develop critical thinking in others', 'hours' => 50],
            ['code' => 'BSBLDR523', 'name' => 'Lead and manage effective workplace relationships', 'hours' => 60],
            ['code' => 'BSBOPS502', 'name' => 'Manage business operational plans', 'hours' => 80],
            ['code' => 'BSBPEF502', 'name' => 'Develop and use emotional intelligence', 'hours' => 50],
            ['code' => 'BSBTWK502', 'name' => 'Manage team effectiveness', 'hours' => 60],
        ],
    ],
    [
        'code' => 'BSB40520',
        'name' => 'Certificate IV in Leadership and Management',
        'units' => [
            ['code' => 'BSBCMM411', 'name' => 'Make presentations', 'hours' => 40],
            ['code' => 'BSBCRT411', 'name' => 'Apply critical thinking to work practices', 'hours' => 40],
            ['code' => 'BSBLDR411', 'name' => 'Demonstrate leadership in the workplace', 'hours' => 40],
            ['code' => 'BSBOPS402', 'name' => 'Coordinate business operational plans', 'hours' => 50],
            ['code' => 'BSBPEF401', 'name' => 'Manage personal health and wellbeing', 'hours' => 30],
        ],
    ],
    [
        'code' => 'BSB30120',
        'name' => 'Certificate III in Business',
        'units' => [
            ['code' => 'BSBCRT311', 'name' => 'Apply critical thinking skills in a team environment', 'hours' => 25],
            ['code' => 'BSBPEF201', 'name' => 'Support personal wellbeing in the workplace', 'hours' => 20],
            ['code' => 'BSBSUS211', 'name' => 'Participate in sustainable work practices', 'hours' => 20],
            ['code' => 'BSBTEC301', 'name' => 'Design and produce business documents', 'hours' => 30],
        ],
    ],
    [
        'code' => 'TAE40122',
        'name' => 'Certificate IV in Training and Assessment',
        'units' => [
            ['code' => 'TAEDEL411', 'name' => 'Address adult language, literacy and numeracy skills', 'hours' => 40],
            ['code' => 'TAEDEL414', 'name' => 'Facilitate group learning', 'hours' => 50],
            ['code' => 'TAEDES411', 'name' => 'Reflect on and improve own professional practice', 'hours' => 20],
            ['code' => 'TAEASS411', 'name' => 'Plan assessment activities and processes', 'hours' => 40],
            ['code' => 'TAEASS412', 'name' => 'Assess competence', 'hours' => 50],
            ['code' => 'TAEASS413', 'name' => 'Participate in assessment validation', 'hours' => 30],
        ],
    ],
    [
        'code' => 'BSBWHS411',
        'name' => 'Certificate IV in Work Health and Safety',
        'units' => [
            ['code' => 'BSBWHS411', 'name' => 'Implement and monitor WHS policies, procedures and programs', 'hours' => 60],
            ['code' => 'BSBWHS412', 'name' => 'Assist with workplace compliance with WHS laws', 'hours' => 50],
            ['code' => 'BSBWHS413', 'name' => 'Contribute to implementation and maintenance of WHS consultation and participation processes', 'hours' => 40],
            ['code' => 'BSBWHS414', 'name' => 'Contribute to WHS risk management', 'hours' => 50],
        ],
    ],
    [
        'code' => 'CHC43015',
        'name' => 'Certificate IV in Ageing Support',
        'units' => [
            ['code' => 'CHCAGE001', 'name' => 'Facilitate the empowerment of older people', 'hours' => 50],
            ['code' => 'CHCAGE003', 'name' => 'Coordinate services for older people', 'hours' => 60],
            ['code' => 'CHCAGE004', 'name' => 'Implement interventions with older people at risk', 'hours' => 50],
            ['code' => 'CHCAGE005', 'name' => 'Provide support to people living with dementia', 'hours' => 60],
        ],
    ],
    [
        'code' => 'HLT33115',
        'name' => 'Certificate III in Health Services Assistance',
        'units' => [
            ['code' => 'HLTAAP001', 'name' => 'Recognise healthy body systems', 'hours' => 40],
            ['code' => 'HLTINF001', 'name' => 'Comply with infection prevention and control policies and procedures', 'hours' => 30],
            ['code' => 'HLTWHS001', 'name' => 'Participate in workplace health and safety', 'hours' => 25],
        ],
    ],
    [
        'code' => 'ICT50220',
        'name' => 'Diploma of Information Technology',
        'units' => [
            ['code' => 'BSBCRT512', 'name' => 'Originate and develop concepts', 'hours' => 50],
            ['code' => 'BSBXCS402', 'name' => 'Promote workplace cyber security awareness and best practices', 'hours' => 40],
            ['code' => 'ICTICT517', 'name' => 'Match ICT needs with the strategic direction of the organisation', 'hours' => 60],
            ['code' => 'ICTICT518', 'name' => 'Research and apply emerging technology innovations', 'hours' => 50],
        ],
    ],
];

// ============================================
// CREATE TEST COURSES
// ============================================

log_message("\n--- Creating Test Courses ---");

$createdcourses = [];
$coursestoCreate = min($options['courses'], count($qualifications));

for ($i = 0; $i < $coursestoCreate; $i++) {
    $qual = $qualifications[$i];
    
    $coursedata = new stdClass();
    $coursedata->fullname = $qual['name'];
    $coursedata->shortname = 'RTOTEST-' . $qual['code'];
    $coursedata->category = 1;
    $coursedata->summary = "Test course for {$qual['name']} ({$qual['code']})";
    $coursedata->format = 'topics';
    $coursedata->visible = 1;
    
    try {
        $course = create_course($coursedata);
        $createdcourses[] = [
            'course' => $course,
            'qualification' => $qual,
        ];
        log_message("  ✓ Created course: {$qual['code']} - {$qual['name']}", true);
    } catch (Exception $e) {
        // Course might already exist
        $existing = $DB->get_record('course', ['shortname' => $coursedata->shortname]);
        if ($existing) {
            $createdcourses[] = [
                'course' => $existing,
                'qualification' => $qual,
            ];
            log_message("  → Course exists: {$qual['code']}", true);
        }
    }
}

log_message("✓ Created/found " . count($createdcourses) . " test courses");

// ============================================
// CREATE TEST TRAINERS
// ============================================

log_message("\n--- Creating Test Trainers ---");

$createdtrainers = [];
$trainernames = [
    ['John', 'Smith', 'Male'],
    ['Sarah', 'Johnson', 'Female'],
    ['Michael', 'Williams', 'Male'],
    ['Emily', 'Brown', 'Female'],
    ['David', 'Jones', 'Male'],
    ['Jessica', 'Davis', 'Female'],
    ['Robert', 'Miller', 'Male'],
    ['Amanda', 'Wilson', 'Female'],
    ['Christopher', 'Moore', 'Male'],
    ['Jennifer', 'Taylor', 'Female'],
];

for ($i = 0; $i < min($options['trainers'], count($trainernames)); $i++) {
    $name = $trainernames[$i];
    
    $userdata = new stdClass();
    $userdata->username = 'rtotest_trainer_' . ($i + 1);
    $userdata->firstname = $name[0];
    $userdata->lastname = $name[1];
    $userdata->email = "trainer{$i}@testtraining.edu.au";
    $userdata->password = 'TestPassword1!';
    $userdata->confirmed = 1;
    $userdata->mnethostid = $CFG->mnet_localhost_id;
    
    $existinguser = $DB->get_record('user', ['username' => $userdata->username]);
    if (!$existinguser) {
        $userid = user_create_user($userdata, true, false);
    } else {
        $userid = $existinguser->id;
    }
    
    // Create trainer record
    $trainerrecord = new stdClass();
    $trainerrecord->userid = $userid;
    $trainerrecord->taecredential = 'TAE40122';
    $trainerrecord->taedateachieved = strtotime('-2 years');
    $trainerrecord->vocationalqualifications = json_encode([
        ['code' => 'BSB50420', 'name' => 'Diploma of Leadership and Management', 'year' => 2018],
        ['code' => 'BSB40520', 'name' => 'Certificate IV in Leadership and Management', 'year' => 2015],
    ]);
    $trainerrecord->industrycurrency = 'Current industry engagement through consulting work and professional memberships';
    $trainerrecord->industrycurrencydate = strtotime('-6 months');
    $trainerrecord->vocationalcompetency = 'Demonstrated through 10+ years experience in management roles';
    $trainerrecord->vocationalcompetencydate = strtotime('-1 year');
    $trainerrecord->cpdhours = rand(20, 50);
    $trainerrecord->cpdlog = json_encode([
        ['activity' => 'Industry Conference', 'hours' => 8, 'date' => date('Y-m-d', strtotime('-3 months'))],
        ['activity' => 'Online Training Course', 'hours' => 4, 'date' => date('Y-m-d', strtotime('-6 months'))],
        ['activity' => 'Workshop Facilitation', 'hours' => 6, 'date' => date('Y-m-d', strtotime('-9 months'))],
    ]);
    $trainerrecord->wwccnumber = 'WWC' . str_pad(rand(1000000, 9999999), 7, '0', STR_PAD_LEFT);
    $trainerrecord->wwccstate = ['NSW', 'VIC', 'QLD', 'SA', 'WA'][rand(0, 4)];
    $trainerrecord->wwccexpiry = strtotime('+3 years');
    $trainerrecord->wwccstatus = 'current';
    $trainerrecord->policechecknumber = 'PC' . rand(100000, 999999);
    $trainerrecord->policecheckdate = strtotime('-1 year');
    $trainerrecord->policecheckexpiry = strtotime('+2 years');
    $trainerrecord->policecheckstatus = 'current';
    $trainerrecord->scopemapping = json_encode(['BSB50420', 'BSB40520', 'BSB30120']);
    $trainerrecord->status = 'current';
    $trainerrecord->compliancestatus = 'compliant';
    $trainerrecord->timecreated = time();
    $trainerrecord->timemodified = time();
    
    $existingtrainer = $DB->get_record('local_rtocompliance_trainers', ['userid' => $userid]);
    if (!$existingtrainer) {
        $DB->insert_record('local_rtocompliance_trainers', $trainerrecord);
    } else {
        $trainerrecord->id = $existingtrainer->id;
        $DB->update_record('local_rtocompliance_trainers', $trainerrecord);
    }
    
    $createdtrainers[] = $userid;
    log_message("  ✓ Created trainer: {$name[0]} {$name[1]}", true);
}

log_message("✓ Created " . count($createdtrainers) . " test trainers");

// ============================================
// CREATE TEST STUDENTS
// ============================================

log_message("\n--- Creating Test Students ---");

$studentfirstnames = ['James', 'Emma', 'Oliver', 'Sophia', 'William', 'Isabella', 'Benjamin', 'Mia', 'Lucas', 'Charlotte', 
                      'Henry', 'Amelia', 'Alexander', 'Harper', 'Mason', 'Evelyn', 'Ethan', 'Abigail', 'Jacob', 'Emily',
                      'Daniel', 'Elizabeth', 'Matthew', 'Sofia', 'Aiden', 'Avery', 'Joseph', 'Ella', 'Jackson', 'Scarlett'];
$studentlastnames = ['Anderson', 'Thomas', 'Jackson', 'White', 'Harris', 'Martin', 'Thompson', 'Garcia', 'Martinez', 'Robinson',
                     'Clark', 'Rodriguez', 'Lewis', 'Lee', 'Walker', 'Hall', 'Allen', 'Young', 'King', 'Wright'];

$createdstudents = [];
$states = array_keys(avetmiss_codes::get_state_codes());
$countries = array_keys(avetmiss_codes::get_country_codes());
$languages = array_keys(avetmiss_codes::get_language_codes());

for ($i = 0; $i < $options['students']; $i++) {
    $firstname = $studentfirstnames[array_rand($studentfirstnames)];
    $lastname = $studentlastnames[array_rand($studentlastnames)];
    $sex = rand(0, 1) ? 'M' : 'F';
    
    $userdata = new stdClass();
    $userdata->username = 'rtotest_student_' . ($i + 1);
    $userdata->firstname = $firstname;
    $userdata->lastname = $lastname;
    $userdata->email = "student{$i}@testtraining.edu.au";
    $userdata->password = 'TestPassword1!';
    $userdata->confirmed = 1;
    $userdata->mnethostid = $CFG->mnet_localhost_id;
    
    $existinguser = $DB->get_record('user', ['username' => $userdata->username]);
    if (!$existinguser) {
        $userid = user_create_user($userdata, true, false);
    } else {
        $userid = $existinguser->id;
    }
    
    // Generate valid USI (10 chars, no 0, 1, I, O)
    $usiChars = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
    $usi = '';
    for ($j = 0; $j < 10; $j++) {
        $usi .= $usiChars[rand(0, strlen($usiChars) - 1)];
    }
    
    $state = $states[array_rand($states)];
    $postcodePrefix = ['01' => '2', '02' => '3', '03' => '4', '04' => '5', '05' => '6', '06' => '7', '07' => '0', '08' => '2'];
    $postcode = ($postcodePrefix[$state] ?? '2') . str_pad(rand(0, 999), 3, '0', STR_PAD_LEFT);
    
    // Create student AVETMISS profile
    $studentrecord = new stdClass();
    $studentrecord->userid = $userid;
    $studentrecord->clientid = str_pad($i + 1001, 10, '0', STR_PAD_LEFT);
    $studentrecord->usi = $usi;
    $studentrecord->usiverified = rand(0, 1);
    $studentrecord->dateofbirth = strtotime('-' . rand(18, 55) . ' years -' . rand(0, 364) . ' days');
    $studentrecord->sex = $sex;
    $studentrecord->indigenousstatus = ['@', '1', '2', '3', '4'][rand(0, 4)];
    $studentrecord->countryofbirth = rand(0, 10) > 7 ? $countries[array_rand($countries)] : '1101'; // 70% Australian
    $studentrecord->languageathome = rand(0, 10) > 8 ? $languages[array_rand($languages)] : '1201'; // 80% English
    $studentrecord->englishproficiency = ['@', '1', '2', '3', '4'][rand(0, 4)];
    $studentrecord->disabilityflag = rand(0, 10) > 8 ? 'Y' : 'N';
    $studentrecord->disabilitytypes = $studentrecord->disabilityflag === 'Y' ? ['11', '12', '13', '14', '15'][rand(0, 4)] : null;
    $studentrecord->streetno = rand(1, 200);
    $studentrecord->streetname = ['Main', 'High', 'Park', 'Queen', 'King', 'George', 'Elizabeth'][rand(0, 6)] . ' Street';
    $studentrecord->suburb = ['Sydney', 'Melbourne', 'Brisbane', 'Perth', 'Adelaide', 'Hobart', 'Darwin', 'Canberra'][rand(0, 7)];
    $studentrecord->postcode = $postcode;
    $studentrecord->statecode = $state;
    $studentrecord->highestschoollevel = ['02', '08', '09', '10', '11', '12', '@@'][rand(0, 6)];
    $studentrecord->yearschoolcompleted = rand(0, 1) ? (string)(rand(1985, 2023)) : '@@@@';
    $studentrecord->atschoolflag = rand(0, 10) > 9 ? 'Y' : 'N';
    $studentrecord->surveycontactstatus = rand(0, 1) ? 'A' : 'N';
    $studentrecord->surveycontactemail = $userdata->email;
    $studentrecord->profilecomplete = rand(0, 10) > 2 ? 1 : 0; // 80% complete
    $studentrecord->timecreated = time();
    $studentrecord->timemodified = time();
    
    $existingstudent = $DB->get_record('local_rtocompliance_students', ['userid' => $userid]);
    if (!$existingstudent) {
        $studentid = $DB->insert_record('local_rtocompliance_students', $studentrecord);
    } else {
        $studentrecord->id = $existingstudent->id;
        $DB->update_record('local_rtocompliance_students', $studentrecord);
        $studentid = $existingstudent->id;
    }
    
    $createdstudents[] = [
        'userid' => $userid,
        'studentid' => $studentid,
        'name' => "$firstname $lastname",
    ];
    
    log_message("  ✓ Created student: $firstname $lastname (USI: $usi)", true);
}

log_message("✓ Created " . count($createdstudents) . " test students");

// ============================================
// CREATE ENROLMENTS
// ============================================

log_message("\n--- Creating Test Enrolments ---");

$outcomes = ['00', '20', '30', '40', '51', '52', '60', '70', '81', '90'];
$completionOutcomes = ['20', '51', '52', '60', '81', '82'];
$deliveryModes = ['10', '20', '30', '40'];
$fundingSources = ['11', '15', '20', '30'];

$enrolmentCount = 0;

foreach ($createdstudents as $student) {
    // Each student gets 1-3 course enrolments
    $numCourses = rand(1, min(3, count($createdcourses)));
    $shuffledCourses = $createdcourses;
    shuffle($shuffledCourses);
    
    for ($c = 0; $c < $numCourses; $c++) {
        $courseData = $shuffledCourses[$c];
        $qual = $courseData['qualification'];
        $course = $courseData['course'];
        
        // Enrol in Moodle course
        $enrol = enrol_get_plugin('manual');
        $instances = enrol_get_instances($course->id, true);
        $manualinstance = null;
        foreach ($instances as $instance) {
            if ($instance->enrol === 'manual') {
                $manualinstance = $instance;
                break;
            }
        }
        
        if ($manualinstance) {
            $enrol->enrol_user($manualinstance, $student['userid']);
        }
        
        // Create enrolment records for each unit
        $startDate = strtotime('-' . rand(30, 365) . ' days');
        $allComplete = rand(0, 10) > 6; // 40% chance all units complete
        
        foreach ($qual['units'] as $unit) {
            $endDate = $startDate + (rand(14, 90) * 86400);
            $outcome = $allComplete ? $completionOutcomes[array_rand($completionOutcomes)] : $outcomes[array_rand($outcomes)];
            
            $enrolrecord = new stdClass();
            $enrolrecord->studentid = $student['studentid'];
            $enrolrecord->courseid = $course->id;
            $enrolrecord->programid = $qual['code'];
            $enrolrecord->programcode = $qual['code'];
            $enrolrecord->programname = $qual['name'];
            $enrolrecord->subjectid = $unit['code'];
            $enrolrecord->unitcode = $unit['code'];
            $enrolrecord->unitname = $unit['name'];
            $enrolrecord->activitystartdate = $startDate;
            $enrolrecord->activityenddate = in_array($outcome, $completionOutcomes) ? $endDate : null;
            $enrolrecord->scheduledhours = $unit['hours'];
            $enrolrecord->outcomeidentifier = $outcome;
            $enrolrecord->deliverymode = $deliveryModes[array_rand($deliveryModes)];
            $enrolrecord->fundingsourcenat = $fundingSources[array_rand($fundingSources)];
            $enrolrecord->deliverylocationid = 'MAIN';
            $enrolrecord->vetflag = 'Y';
            $enrolrecord->vetinschoolsflag = 'N';
            $enrolrecord->commencingprogramid = $c == 0 ? '1' : '3';
            $enrolrecord->status = in_array($outcome, $completionOutcomes) ? 'completed' : (in_array($outcome, ['40', '30']) ? 'withdrawn' : 'active');
            $enrolrecord->tuitionfee = rand(50, 500);
            $enrolrecord->feecharged = 'Y';
            $enrolrecord->assessoruserid = $createdtrainers[array_rand($createdtrainers)];
            $enrolrecord->assessmentdate = in_array($outcome, $completionOutcomes) ? $endDate : null;
            $enrolrecord->timecreated = time();
            $enrolrecord->timemodified = time();
            
            // Set program completion if all units complete
            if ($allComplete) {
                $enrolrecord->programcompletedyear = date('Y');
                $enrolrecord->programoutcome = '01';
            }
            
            $DB->insert_record('local_rtocompliance_enrolments', $enrolrecord);
            $enrolmentCount++;
        }
    }
}

log_message("✓ Created $enrolmentCount test enrolments");

// ============================================
// CREATE SAMPLE CERTIFICATES
// ============================================

log_message("\n--- Creating Sample Certificates ---");

$certCount = 0;
$certTypes = ['testamur', 'statement', 'statement', 'attendance']; // Weight towards statements

// Pick random students who have completions
$studentsWithCompletions = $DB->get_records_sql(
    "SELECT DISTINCT s.id as studentid, s.userid 
     FROM {local_rtocompliance_students} s
     JOIN {local_rtocompliance_enrolments} e ON e.studentid = s.id
     WHERE e.outcomeidentifier IN ('20', '51', '52', '60', '81', '82')
     AND s.usi IS NOT NULL AND s.usi != ''
     LIMIT 10"
);

foreach ($studentsWithCompletions as $student) {
    $certtype = $certTypes[array_rand($certTypes)];
    
    // Get qualification info
    $enrolment = $DB->get_record_sql(
        "SELECT * FROM {local_rtocompliance_enrolments} 
         WHERE studentid = ? AND outcomeidentifier IN ('20', '51', '52', '60', '81', '82')
         LIMIT 1",
        [$student->studentid]
    );
    
    if (!$enrolment) continue;
    
    $certnumber = 'CERT-' . date('Y') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
    $verifytoken = bin2hex(random_bytes(32));
    
    $certrecord = new stdClass();
    $certrecord->userid = $student->userid;
    $certrecord->certnumber = $certnumber;
    $certrecord->certtype = $certtype;
    $certrecord->qualificationcode = $enrolment->programcode;
    $certrecord->qualificationname = $enrolment->programname;
    
    if ($certtype === 'statement') {
        $units = $DB->get_records_sql(
            "SELECT unitcode, unitname, outcomeidentifier FROM {local_rtocompliance_enrolments} 
             WHERE studentid = ? AND outcomeidentifier IN ('20', '51', '52', '60', '81', '82')",
            [$student->studentid]
        );
        $certrecord->units = json_encode(array_values(array_map(function ($u) {
            return ['code' => $u->unitcode, 'name' => $u->unitname, 'outcome' => $u->outcomeidentifier];
        }, $units)));
    }
    
    $certrecord->issuedate = strtotime('-' . rand(1, 180) . ' days');
    $certrecord->verifytoken = $verifytoken;
    $certrecord->status = 'issued';
    $certrecord->issuedby = $createdtrainers[0];
    $certrecord->emailsent = rand(0, 1);
    $certrecord->emailsentdate = $certrecord->emailsent ? $certrecord->issuedate + rand(3600, 86400) : null;
    $certrecord->timecreated = time();
    $certrecord->timemodified = time();
    
    $DB->insert_record('local_rtocompliance_certs', $certrecord);
    $certCount++;
}

log_message("✓ Created $certCount sample certificates");

// ============================================
// CREATE SURVEY RESPONSES
// ============================================

log_message("\n--- Creating Survey Responses ---");

$surveyCount = 0;
$year = (int)date('Y');

// Learner surveys
for ($i = 0; $i < 15; $i++) {
    $student = $createdstudents[array_rand($createdstudents)];
    
    $surveyrecord = new stdClass();
    $surveyrecord->surveytype = 'learner';
    $surveyrecord->respondentid = $student['userid'];
    $surveyrecord->respondentname = $student['name'];
    $surveyrecord->respondentemail = "student{$i}@testtraining.edu.au";
    $surveyrecord->responses = json_encode([
        'q1_training_relevant' => rand(1, 5),
        'q2_trainers_knowledge' => rand(1, 5),
        'q3_assessment_fair' => rand(1, 5),
        'q4_facilities_adequate' => rand(1, 5),
        'q5_support_available' => rand(1, 5),
        'q6_recommend_rto' => rand(1, 5),
    ]);
    $surveyrecord->overallsatisfaction = rand(3, 5);
    $surveyrecord->comments = rand(0, 1) ? 'Great training experience, learned a lot!' : null;
    $surveyrecord->year = $year;
    $surveyrecord->accesstoken = bin2hex(random_bytes(32));
    $surveyrecord->status = 'completed';
    $surveyrecord->timecreated = strtotime('-' . rand(1, 90) . ' days');
    $surveyrecord->timecompleted = $surveyrecord->timecreated + rand(300, 1800);
    
    $DB->insert_record('local_rtocompliance_surveys', $surveyrecord);
    $surveyCount++;
}

// Employer surveys
for ($i = 0; $i < 5; $i++) {
    $surveyrecord = new stdClass();
    $surveyrecord->surveytype = 'employer';
    $surveyrecord->respondentname = ['Acme Corp', 'Tech Solutions', 'Healthcare Plus', 'Finance Group', 'Retail Co'][$i];
    $surveyrecord->respondentemail = "employer{$i}@company.com.au";
    $surveyrecord->responses = json_encode([
        'q1_employees_skills' => rand(1, 5),
        'q2_training_relevant' => rand(1, 5),
        'q3_recommend_rto' => rand(1, 5),
        'q4_communication' => rand(1, 5),
    ]);
    $surveyrecord->overallsatisfaction = rand(3, 5);
    $surveyrecord->year = $year;
    $surveyrecord->accesstoken = bin2hex(random_bytes(32));
    $surveyrecord->status = 'completed';
    $surveyrecord->timecreated = strtotime('-' . rand(1, 90) . ' days');
    $surveyrecord->timecompleted = $surveyrecord->timecreated + rand(300, 1800);
    
    $DB->insert_record('local_rtocompliance_surveys', $surveyrecord);
    $surveyCount++;
}

log_message("✓ Created $surveyCount survey responses");

// ============================================
// CREATE AUDIT LOG ENTRIES
// ============================================

log_message("\n--- Creating Audit Log Entries ---");

$logActions = [
    ['action' => 'student_created', 'component' => 'students'],
    ['action' => 'profile_updated', 'component' => 'students'],
    ['action' => 'enrolment_created', 'component' => 'enrolments'],
    ['action' => 'outcome_recorded', 'component' => 'enrolments'],
    ['action' => 'certificate_issued', 'component' => 'certs'],
    ['action' => 'nat_exported', 'component' => 'nat'],
    ['action' => 'trainer_updated', 'component' => 'trainers'],
    ['action' => 'survey_completed', 'component' => 'surveys'],
];

for ($i = 0; $i < 50; $i++) {
    $action = $logActions[array_rand($logActions)];
    $student = $createdstudents[array_rand($createdstudents)];
    
    $logrecord = new stdClass();
    $logrecord->action = $action['action'];
    $logrecord->component = $action['component'];
    $logrecord->itemid = rand(1, 100);
    $logrecord->userid = $createdtrainers[array_rand($createdtrainers)];
    $logrecord->targetuserid = $student['userid'];
    $logrecord->details = json_encode(['source' => 'fixture_seed', 'test' => true]);
    $logrecord->ipaddress = '192.168.1.' . rand(1, 254);
    $logrecord->timecreated = strtotime('-' . rand(1, 180) . ' days');
    
    $DB->insert_record('local_rtocompliance_log', $logrecord);
}

log_message("✓ Created 50 audit log entries");

// ============================================
// SUMMARY
// ============================================

log_message("\n=== Fixture Seeding Complete ===\n");
log_message("Summary:");
log_message("  • RTO Code: {$rtoconfig['rtocode']}");
log_message("  • Courses: " . count($createdcourses));
log_message("  • Trainers: " . count($createdtrainers));
log_message("  • Students: " . count($createdstudents));
log_message("  • Enrolments: $enrolmentCount");
log_message("  • Certificates: $certCount");
log_message("  • Surveys: $surveyCount");
log_message("");
log_message("Test user credentials:");
log_message("  • Username pattern: rtotest_student_N or rtotest_trainer_N");
log_message("  • Password: TestPassword1!");
log_message("");
log_message("Ready for testing!");

exit(0);
