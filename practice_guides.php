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
 * RTO Compliance plugin — practice_guides.php.
 *
 * @package    local_rtocompliance
 * @copyright  2025 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/lib.php');

admin_externalpage_setup('local_rtocompliance_practiceguides');
// ACCESS (v6.3.8): admin_externalpage_setup() above already enforces the capability this
// page is registered with in settings.php. Stating it explicitly keeps the requirement
// visible in this file instead of only in the settings registration — same check, no cost.
require_capability('local/rtocompliance:manage', context_system::instance());
require_login();

$guideid = optional_param('guide', '', PARAM_ALPHANUMEXT);

$PAGE->set_url('/local/rtocompliance/practice_guides.php', ['guide' => $guideid]);
$PAGE->set_title('ASQA Practice Guides - Self-Assurance');
$PAGE->set_heading(get_string('pluginname', 'local_rtocompliance'));

$PAGE->requires->css('/local/rtocompliance/styles.css');

function pg_icon($name, $class = '') {
    $icons = [
        'book-open' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>',
        'clipboard-list' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="8" y="2" width="8" height="4" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M12 11h4"/><path d="M12 16h4"/><path d="M8 11h.01"/><path d="M8 16h.01"/></svg>',
        'check-circle' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>',
        'users' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
        'user-check' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><polyline points="16 11 18 13 22 9"/></svg>',
        'building' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="2" width="16" height="20" rx="2" ry="2"/><path d="M9 22v-4h6v4"/><path d="M8 6h.01"/><path d="M16 6h.01"/><path d="M12 6h.01"/><path d="M12 10h.01"/><path d="M12 14h.01"/><path d="M16 10h.01"/><path d="M16 14h.01"/><path d="M8 10h.01"/><path d="M8 14h.01"/></svg>',
        'shield-check' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10"/><path d="m9 12 2 2 4-4"/></svg>',
        'alert-triangle' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>',
        'refresh-cw' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M3 21v-5h5"/></svg>',
        'file-text' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><line x1="10" y1="9" x2="8" y2="9"/></svg>',
        'award' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="6"/><path d="M15.477 12.89 17 22l-5-3-5 3 1.523-9.11"/></svg>',
        'heart' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/></svg>',
        'message-circle' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m3 21 1.9-5.7a8.5 8.5 0 1 1 3.8 3.8z"/></svg>',
        'info' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>',
        'graduation-cap' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c0 2 2 3 6 3s6-1 6-3v-5"/></svg>',
        'target' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg>',
        'briefcase' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>',
        'external-link' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>',
        'arrow-left' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>',
        'arrow-right' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>',
        'download' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>',
    ];
    
    $svg = $icons[$name] ?? $icons['file-text'];
    $svg = preg_replace('/<svg /', '<svg width="20" height="20" ', $svg);
    $classes = 'rtoc-icon' . ($class ? ' ' . $class : '');
    return '<span class="' . $classes . '">' . $svg . '</span>';
}

$practiceGuides = [
    'training' => [
        'title' => 'Training',
        'standards' => '1.1, 1.2',
        'quality_area' => 1,
        'color' => 'amber',
        'icon' => 'clipboard-list',
        'url' => 'https://www.asqa.gov.au/rtos/2025-standards-rtos/practice-guides/practice-guide-training',
        'pdf' => 'https://www.asqa.gov.au/media/2476',
        'description' => 'Training product requirements, mode of delivery, structure, pacing, clustering, training techniques and work placements.',
        'questions' => [
            ['q' => 'How do you know your training design and delivery is fit-for-purpose and consistent with the requirements of the training product?', 'mapping' => 'TAS Builder automatically maps training to training product requirements and validates packaging rules.', 'link' => '/local/rtocompliance/tas.php'],
            ['q' => 'How do you identify relevant industry, employer and/or community representatives and engage with them to ensure your training reflects current industry requirements, expectations and practice?', 'mapping' => 'TAS includes industry consultation records with dated evidence of engagement and feedback.', 'link' => '/local/rtocompliance/tas.php'],
            ['q' => 'What has informed your understanding that the structure and pacing of training allows students to achieve the outcomes set out in the training product? How do you adjust this for different student cohorts?', 'mapping' => 'TAS Builder calculates volume of learning and tracks delivery modes with cohort-specific adjustments.', 'link' => '/local/rtocompliance/tas.php'],
            ['q' => 'How do you ensure trainers are appropriately skilled, qualified and resourced to deliver training in an effective and engaging way?', 'mapping' => 'Trainer Credentials module tracks TAE qualifications, industry currency, and professional development.', 'link' => '/local/rtocompliance/trainers.php'],
            ['q' => 'How do you collect industry, employer and/or community representatives and student feedback and use this to inform improvements to training design and delivery?', 'mapping' => 'Quality Indicator Surveys collect learner and employer feedback with automated analysis.', 'link' => '/local/rtocompliance/surveys.php'],
            ['q' => 'How do you evaluate whether work placements provide students with sufficient opportunity to gain the necessary industry-relevant skills and knowledge?', 'mapping' => 'Third Party Arrangements register tracks work placement agreements and outcomes.', 'link' => '/local/rtocompliance/thirdparty.php'],
        ]
    ],
    'assessment' => [
        'title' => 'Assessment',
        'standards' => '1.3, 1.4, 1.5',
        'quality_area' => 1,
        'color' => 'amber',
        'icon' => 'check-circle',
        'url' => 'https://www.asqa.gov.au/rtos/2025-standards-rtos/practice-guides/practice-guide-assessment',
        'pdf' => 'https://www.asqa.gov.au/media/2473',
        'description' => 'Assessing competency, assessment systems, reviewing assessment tools, principles of assessment, rules of evidence and validation.',
        'questions' => [
            ['q' => 'How do you know your assessment system supports assessors to make consistent assessment judgements that are in line with the requirements of the training product?', 'mapping' => 'Validation Schedule tracks assessment tool reviews with documented outcomes and improvements.', 'link' => '/local/rtocompliance/validation.php'],
            ['q' => 'How do you ensure that assessors apply the principles of assessment and rules of evidence when making assessment judgements?', 'mapping' => 'Validation Schedule tracks assessor compliance with the principles of assessment and rules of evidence.', 'link' => '/local/rtocompliance/validation.php'],
            ['q' => 'How do you review assessment tools prior to use?', 'mapping' => 'Validation Events record pre-use review with industry input and moderation outcomes.', 'link' => '/local/rtocompliance/validation.php'],
            ['q' => 'How do you determine the components of your assessment system and the sample of assessments that should be validated?', 'mapping' => 'Validation Schedule uses risk-based approach with documented rationale for sampling.', 'link' => '/local/rtocompliance/validation.php'],
            ['q' => 'What outcomes has validation identified and how have these informed improvements to your assessment practices?', 'mapping' => 'Validation Events track findings, required actions, and evidence of implementation.', 'link' => '/local/rtocompliance/validation.php'],
            ['q' => 'How do you ensure persons involved in validation have the skills and knowledge to undertake validation effectively?', 'mapping' => 'Trainer credentials track validation experience and relevant qualifications.', 'link' => '/local/rtocompliance/trainers.php'],
        ]
    ],
    'rpl-credit' => [
        'title' => 'Recognition of Prior Learning and Credit Transfer',
        'standards' => '1.6, 1.7',
        'quality_area' => 1,
        'color' => 'amber',
        'icon' => 'award',
        'url' => 'https://www.asqa.gov.au/rtos/2025-standards-rtos/practice-guides/practice-guide-recognition-prior-learning-and-credit-transfer',
        'pdf' => 'https://www.asqa.gov.au/media/2509',
        'description' => 'Recognition of prior learning processes, credit transfer arrangements and maintaining assessment quality.',
        'questions' => [
            ['q' => 'How do you offer recognition of prior learning to all students and support them through the process?', 'mapping' => 'RPL Register tracks all RPL applications with documented evidence, assessor judgements, and outcomes.', 'link' => '/local/rtocompliance/rpl.php'],
            ['q' => 'How do you ensure that assessors make consistent RPL judgements?', 'mapping' => 'Validation includes RPL assessment samples and moderation outcomes.', 'link' => '/local/rtocompliance/validation.php'],
            ['q' => 'How do you offer credit transfer to students and verify the authenticity of qualifications or statements of attainment?', 'mapping' => 'Certificate register validates credentials and maintains 30-year records.', 'link' => '/local/rtocompliance/certificates.php'],
        ]
    ],
    'facilities' => [
        'title' => 'Facilities, Resources and Equipment',
        'standards' => '1.8',
        'quality_area' => 1,
        'color' => 'amber',
        'icon' => 'briefcase',
        'url' => 'https://www.asqa.gov.au/rtos/2025-standards-rtos/practice-guides/practice-guide-facilities-resources-and-equipment',
        'pdf' => 'https://www.asqa.gov.au/media/2512',
        'description' => 'Ensuring appropriate facilities, learning resources and equipment for quality training and assessment.',
        'questions' => [
            ['q' => 'How do you ensure that the facilities, equipment and learning resources you use are fit-for-purpose?', 'mapping' => 'TAS Builder documents resources required for each training product with adequacy evidence.', 'link' => '/local/rtocompliance/tas.php'],
            ['q' => 'How do you ensure facilities, equipment and resources are maintained and remain current?', 'mapping' => 'TAS Builder documents resource maintenance schedules, review cycles, and currency evidence for each training product.', 'link' => '/local/rtocompliance/tas.php'],
        ]
    ],
    'information' => [
        'title' => 'Information',
        'standards' => '2.1, 2.2',
        'quality_area' => 2,
        'color' => 'blue',
        'icon' => 'info',
        'url' => 'https://www.asqa.gov.au/node/6401',
        'pdf' => 'https://www.asqa.gov.au/media/2515',
        'description' => 'Marketing information, pre-enrolment information and ensuring students are fully informed.',
        'questions' => [
            ['q' => 'How do you ensure marketing and advertising information is accurate and not misleading?', 'mapping' => 'Marketing Information module tracks marketing approvals, compliance reviews and pre-enrolment materials.', 'link' => '/local/rtocompliance/marketing_info.php'],
            ['q' => 'How do you ensure students receive all required pre-enrolment information?', 'mapping' => 'Marketing Information module tracks pre-enrolment checklist completion with dated evidence.', 'link' => '/local/rtocompliance/marketing_info.php'],
            ['q' => 'How do you know that students understand the information provided to them?', 'mapping' => 'Marketing Information module includes signed acknowledgements and enrolment terms records.', 'link' => '/local/rtocompliance/marketing_info.php'],
        ]
    ],
    'training-support' => [
        'title' => 'Training Support',
        'standards' => '2.3, 2.4',
        'quality_area' => 2,
        'color' => 'blue',
        'icon' => 'graduation-cap',
        'url' => 'https://www.asqa.gov.au/rtos/2025-standards-rtos/practice-guides/practice-guide-training-support',
        'pdf' => 'https://www.asqa.gov.au/media/2518',
        'description' => 'Identifying student support needs and providing appropriate training support services.',
        'questions' => [
            ['q' => 'How do you identify each student\'s training support needs?', 'mapping' => 'Student Support module captures LLN screening results, identified needs and reasonable adjustments.', 'link' => '/local/rtocompliance/student_support.php'],
            ['q' => 'How do you provide support to students throughout their training?', 'mapping' => 'Student Support module tracks support interventions, referrals and progress monitoring.', 'link' => '/local/rtocompliance/student_support.php'],
            ['q' => 'How do you evaluate whether the support provided to students is effective?', 'mapping' => 'Quality Indicator Surveys capture student satisfaction with support services.', 'link' => '/local/rtocompliance/surveys.php'],
        ]
    ],
    'diversity' => [
        'title' => 'Diversity and Inclusion',
        'standards' => '2.5',
        'quality_area' => 2,
        'color' => 'blue',
        'icon' => 'users',
        'url' => 'https://www.asqa.gov.au/rtos/2025-standards-rtos/practice-guides/practice-guide-diversity-and-inclusion',
        'pdf' => 'https://www.asqa.gov.au/media/2521',
        'description' => 'Providing inclusive and accessible training for all students.',
        'questions' => [
            ['q' => 'How do you ensure training and assessment is inclusive and accessible to all students?', 'mapping' => 'Student Support module tracks reasonable adjustments, accessibility accommodations and diversity records.', 'link' => '/local/rtocompliance/student_support.php'],
            ['q' => 'How do you identify and respond to barriers that may prevent students from participating?', 'mapping' => 'Complaints register captures accessibility feedback and improvement actions.', 'link' => '/local/rtocompliance/complaints.php'],
        ]
    ],
    'wellbeing' => [
        'title' => 'Wellbeing',
        'standards' => '2.6',
        'quality_area' => 2,
        'color' => 'blue',
        'icon' => 'heart',
        'url' => 'https://www.asqa.gov.au/rtos/2025-standards-rtos/practice-guides/practice-guide-wellbeing',
        'pdf' => 'https://www.asqa.gov.au/media/2524',
        'description' => 'Supporting student wellbeing and safety throughout their training.',
        'questions' => [
            ['q' => 'How do you create an environment that supports student wellbeing?', 'mapping' => 'Student Support module documents wellbeing policies, support procedures and at-risk identification.', 'link' => '/local/rtocompliance/student_support.php'],
            ['q' => 'How do you identify and respond to students who may be at risk?', 'mapping' => 'Student Support module tracks welfare concerns, intervention records and support outcomes.', 'link' => '/local/rtocompliance/student_support.php'],
        ]
    ],
    'feedback-complaints' => [
        'title' => 'Feedback, Complaints and Appeals',
        'standards' => '2.7, 2.8',
        'quality_area' => 2,
        'color' => 'blue',
        'icon' => 'message-circle',
        'url' => 'https://www.asqa.gov.au/rtos/2025-standards-rtos/practice-guides/practice-guide-feedback-complaints-and-appeals',
        'pdf' => 'https://www.asqa.gov.au/media/2527',
        'description' => 'Managing feedback, complaints and appeals processes effectively.',
        'questions' => [
            ['q' => 'How do you collect and use feedback to improve training and services?', 'mapping' => 'Quality Indicator Surveys automate feedback collection with trend analysis.', 'link' => '/local/rtocompliance/surveys.php'],
            ['q' => 'How do you ensure your complaints and appeals process is accessible and fair?', 'mapping' => 'Complaints & Appeals Register tracks all cases with required timeframes and outcomes.', 'link' => '/local/rtocompliance/complaints.php'],
            ['q' => 'How do you use complaints and appeals to inform improvements?', 'mapping' => 'Continuous Improvement register links complaint outcomes to systemic changes.', 'link' => '/local/rtocompliance/complaints.php?tab=improvement'],
        ]
    ],
    'workforce-management' => [
        'title' => 'VET Workforce Management',
        'standards' => '3.1',
        'quality_area' => 3,
        'color' => 'green',
        'icon' => 'users',
        'url' => 'https://www.asqa.gov.au/rtos/2025-standards-rtos/practice-guides/practice-guide-vet-workforce-management',
        'pdf' => 'https://www.asqa.gov.au/media/2530',
        'description' => 'Ensuring appropriate staffing levels and workforce planning.',
        'questions' => [
            ['q' => 'How do you ensure you have sufficient trainers and assessors to deliver quality training?', 'mapping' => 'VET Workforce Management module tracks staffing levels, student ratios and workforce capacity by scope area.', 'link' => '/local/rtocompliance/workforce_management.php'],
            ['q' => 'How do you plan for workforce changes and maintain capacity?', 'mapping' => 'VET Workforce Management module documents workforce planning, succession arrangements and gap mitigation.', 'link' => '/local/rtocompliance/workforce_management.php'],
        ]
    ],
    'trainer-competencies' => [
        'title' => 'Trainer and Assessor Competencies',
        'standards' => '3.2, 3.3',
        'quality_area' => 3,
        'color' => 'green',
        'icon' => 'user-check',
        'url' => 'https://www.asqa.gov.au/rtos/2025-standards-rtos/practice-guides/practice-guide-trainer-and-assessor-competencies',
        'pdf' => 'https://www.asqa.gov.au/media/2506',
        'description' => 'The Credential Policy, working under direction, professional development, industry competencies and currency.',
        'questions' => [
            ['q' => 'How do you verify that each person delivering training and assessment for your RTO is appropriately credentialled?', 'mapping' => 'Trainer Credentials module validates TAE qualifications against Credential Policy requirements with 14-type evidence checklist.', 'link' => '/local/rtocompliance/trainers.php'],
            ['q' => 'How do you ensure that you are engaging with industry regularly to assure yourself that trainers and assessors have current industry skills?', 'mapping' => 'Industry Currency tracking with evidence uploads and review schedules.', 'link' => '/local/rtocompliance/trainers.php'],
            ['q' => 'How do you know that your system for monitoring those working under direction is effective?', 'mapping' => 'Supervision Logs track all supervision sessions with competency progression.', 'link' => '/local/rtocompliance/supervision.php'],
            ['q' => 'How do you monitor and regularly review the performance of trainers and assessors to identify opportunities for professional development?', 'mapping' => 'Professional Development tracking with scheduled reviews and CPD records.', 'link' => '/local/rtocompliance/trainers.php'],
            ['q' => 'How do you identify the types of industry competencies, skills and knowledge relevant to each training product on your scope of registration?', 'mapping' => 'TAS Builder maps trainer requirements to training products with scope coverage.', 'link' => '/local/rtocompliance/tas.php'],
            ['q' => 'How do you identify and address gaps in your trainers and assessors\' industry competencies, skills and knowledge?', 'mapping' => 'Trainer credentials identify scope gaps with action plans and review dates.', 'link' => '/local/rtocompliance/trainers.php'],
            ['q' => 'How do you ensure your use of industry experts adds value to training and assessment outcomes?', 'mapping' => 'Supervision Logs track industry expert contributions with outcome evaluation.', 'link' => '/local/rtocompliance/supervision.php'],
        ]
    ],
    'leadership' => [
        'title' => 'Leadership and Accountability',
        'standards' => '4.1, 4.2',
        'quality_area' => 4,
        'color' => 'purple',
        'icon' => 'building',
        'url' => 'https://www.asqa.gov.au/rtos/2025-standards-rtos/practice-guides/practice-guide-leadership-and-accountability',
        'pdf' => 'https://www.asqa.gov.au/media/2474',
        'description' => 'Suitability of governing persons, organisational culture, roles, responsibilities and third party compliance.',
        'questions' => [
            ['q' => 'How do you ensure governing persons are suitable, fit and proper and remain so throughout their engagement with your RTO?', 'mapping' => 'Governance Module tracks ADC appointments with Fit & Proper Person declarations.', 'link' => '/local/rtocompliance/governance.php?tab=adc'],
            ['q' => 'What systems and processes are in place to ensure governing persons are acting diligently and within their delegated authority?', 'mapping' => 'Governance tracks delegations, meeting minutes and decision records.', 'link' => '/local/rtocompliance/governance.php'],
            ['q' => 'How do you ensure governing persons are familiar with the Standards and aware of their responsibility to monitor the organisation\'s performance against them?', 'mapping' => 'Governance documents policy acknowledgements and training records.', 'link' => '/local/rtocompliance/governance.php'],
            ['q' => 'How does the organisation communicate and monitor its values and drive a culture of compliance and continuous improvement?', 'mapping' => 'Continuous Improvement register tracks cultural initiatives and compliance reviews.', 'link' => '/local/rtocompliance/complaints.php?tab=improvement'],
            ['q' => 'What due diligence do you do during your recruitment and performance management of your RTO staff to ensure that they are fit and proper for their role?', 'mapping' => 'Trainer credentials include due diligence checks and ongoing review records.', 'link' => '/local/rtocompliance/trainers.php'],
            ['q' => 'What systems and processes do you have in place to determine, communicate and monitor staff roles, responsibilities and accountabilities within the RTO?', 'mapping' => 'Governance tracks organisational structure with role descriptions and accountabilities.', 'link' => '/local/rtocompliance/governance.php'],
            ['q' => 'How do you ensure staff remain familiar with the Standards and any changes to regulatory requirements?', 'mapping' => 'Governance tracks staff training and regulatory update acknowledgements.', 'link' => '/local/rtocompliance/governance.php'],
            ['q' => 'How do you support staff to voluntarily report compliance and integrity risks?', 'mapping' => 'Complaints register accepts internal reports with whistleblower protections.', 'link' => '/local/rtocompliance/complaints.php'],
            ['q' => 'What systems, processes and monitoring activities do you have in place to oversee and ensure third party compliance with the Standards?', 'mapping' => 'Third Party Arrangements register tracks agreements, monitoring and compliance audits.', 'link' => '/local/rtocompliance/thirdparty.php'],
        ]
    ],
    'risk-management' => [
        'title' => 'Risk Management',
        'standards' => '4.3',
        'quality_area' => 4,
        'color' => 'purple',
        'icon' => 'alert-triangle',
        'url' => 'https://www.asqa.gov.au/rtos/2025-standards-rtos/practice-guides/practice-guide-risk-management',
        'pdf' => 'https://www.asqa.gov.au/media/2533',
        'description' => 'Identifying, assessing and managing risks to training quality and compliance.',
        'questions' => [
            ['q' => 'How do you identify and assess risks to training quality and student outcomes?', 'mapping' => 'Governance tracks risk registers with assessment and mitigation strategies.', 'link' => '/local/rtocompliance/governance.php?tab=changes'],
            ['q' => 'How do you notify ASQA of material changes that may affect your compliance?', 'mapping' => 'Material Changes register tracks notifications with evidence and ASQA responses.', 'link' => '/local/rtocompliance/governance.php?tab=changes'],
        ]
    ],
    'continuous-improvement' => [
        'title' => 'Continuous Improvement',
        'standards' => '4.4',
        'quality_area' => 4,
        'color' => 'purple',
        'icon' => 'refresh-cw',
        'url' => 'https://www.asqa.gov.au/rtos/2025-standards-rtos/practice-guides/practice-guide-continuous-improvement',
        'pdf' => 'https://www.asqa.gov.au/media/2536',
        'description' => 'Systematically reviewing and improving training and assessment practices.',
        'questions' => [
            ['q' => 'How do you systematically evaluate and improve your training and assessment strategies and practices?', 'mapping' => 'Continuous Improvement register tracks all improvement initiatives with outcomes.', 'link' => '/local/rtocompliance/complaints.php?tab=improvement'],
            ['q' => 'How do you use data and evidence to drive continuous improvement?', 'mapping' => 'Dashboard provides real-time metrics on compliance indicators and trends.', 'link' => '/local/rtocompliance/index.php'],
        ]
    ],
    'information-transparency' => [
        'title' => 'Information and Transparency',
        'standards' => 'Compliance 5.1-5.3',
        'quality_area' => 5,
        'color' => 'rose',
        'icon' => 'file-text',
        'url' => 'https://www.asqa.gov.au/rtos/2025-standards-rtos/practice-guides/practice-guide-information-and-transparency',
        'pdf' => 'https://www.asqa.gov.au/media/2539',
        'description' => 'Accurate marketing, transparency in fees and keeping ASQA informed.',
        'questions' => [
            ['q' => 'How do you ensure your marketing is accurate and complies with Australian Consumer Law?', 'mapping' => 'Governance tracks marketing review and approval processes.', 'link' => '/local/rtocompliance/governance.php'],
            ['q' => 'How do you ensure fee information is clear and transparent to students?', 'mapping' => 'Fee Protection register documents all fee schedules and refund policies.', 'link' => '/local/rtocompliance/feeprotection.php'],
        ]
    ],
    'integrity' => [
        'title' => 'Integrity of Nationally Recognised Training',
        'standards' => 'Compliance 9-14',
        'quality_area' => 6,
        'color' => 'rose',
        'icon' => 'shield-check',
        'url' => 'https://www.asqa.gov.au/rtos/2025-standards-rtos/practice-guides/integrity-nationally-recognised-training-products',
        'pdf' => 'https://www.asqa.gov.au/media/2542',
        'description' => 'Issuing qualifications, maintaining records and protecting credential integrity.',
        'questions' => [
            ['q' => 'How do you ensure qualifications and statements of attainment are only issued to students who have been assessed as competent?', 'mapping' => 'Certificate Register links issuance to verified competency records with audit trail.', 'link' => '/local/rtocompliance/certificates.php'],
            ['q' => 'How do you issue credentials within the required 30-day timeframe?', 'mapping' => 'Dashboard alerts track pending certificates with 30-day compliance monitoring.', 'link' => '/local/rtocompliance/certificates.php'],
            ['q' => 'How do you maintain records for the required 30-year retention period?', 'mapping' => 'Certificate Register maintains perpetual records with secure backup.', 'link' => '/local/rtocompliance/certificates.php'],
        ]
    ],
    'accountability' => [
        'title' => 'Accountability',
        'standards' => 'Compliance 7.1-7.4',
        'quality_area' => 7,
        'color' => 'rose',
        'icon' => 'target',
        'url' => 'https://www.asqa.gov.au/rtos/2025-standards-rtos/practice-guides/practice-guide-accountability',
        'pdf' => 'https://www.asqa.gov.au/media/2545',
        'description' => 'Fee protection, insurance requirements and financial viability.',
        'questions' => [
            ['q' => 'How do you protect prepaid fees over $1,500?', 'mapping' => 'Fee Protection register tracks all prepaid fees with protection evidence.', 'link' => '/local/rtocompliance/feeprotection.php'],
            ['q' => 'How do you ensure adequate insurance coverage is maintained?', 'mapping' => 'Insurance Register tracks all policies with expiry alerts and coverage verification.', 'link' => '/local/rtocompliance/insurance.php'],
        ]
    ],
    'fit-proper' => [
        'title' => 'Fit and Proper Person Requirements',
        'standards' => 'Compliance 8.1-8.6',
        'quality_area' => 8,
        'color' => 'rose',
        'icon' => 'user-check',
        'url' => 'https://www.asqa.gov.au/rtos/2025-standards-rtos/practice-guides/practice-guide-fit-and-proper-person-requirements',
        'pdf' => 'https://www.asqa.gov.au/media/2548',
        'description' => 'Requirements for RTOs and their governing persons to be fit and proper.',
        'questions' => [
            ['q' => 'How do you ensure your organisation and governing persons meet the Fit and Proper Person Requirements?', 'mapping' => 'Governance tracks ADC declarations with evidence of ongoing compliance.', 'link' => '/local/rtocompliance/governance.php?tab=adc'],
            ['q' => 'How do you monitor for changes that may affect fit and proper person status?', 'mapping' => 'Governance alerts track declaration renewals and material change notifications.', 'link' => '/local/rtocompliance/governance.php?tab=adc'],
        ]
    ],
    'credential-policy' => [
        'title' => 'Credential Policy',
        'standards' => 'Credential Policy',
        'quality_area' => 0,
        'color' => 'teal',
        'icon' => 'award',
        'url' => 'https://www.asqa.gov.au/rtos/2025-standards-rtos/practice-guides/practice-guide-credential-policy',
        'pdf' => 'https://www.asqa.gov.au/media/2551',
        'description' => 'Requirements for trainer and assessor credentials under the 2025 Standards.',
        'questions' => [
            ['q' => 'How do you ensure all trainers and assessors hold the required credentials under the Credential Policy?', 'mapping' => 'Trainer Credentials module validates against all Credential Policy sections (1A-1D, 2A-2C, 3A-3B) with 14-type evidence checklist.', 'link' => '/local/rtocompliance/trainers.php'],
            ['q' => 'How do you manage trainers working under direction?', 'mapping' => 'Supervision Logs track all directed work with qualified supervisor assignments.', 'link' => '/local/rtocompliance/supervision.php'],
            ['q' => 'How do you ensure professional development maintains trainer currency?', 'mapping' => 'Trainer credentials track CPD hours with evidence and review schedules.', 'link' => '/local/rtocompliance/trainers.php'],
        ]
    ],
];

$PAGE->add_body_class("path-local-rtocompliance");
echo $OUTPUT->header();

if ($guideid && isset($practiceGuides[$guideid])) {
    $guide = $practiceGuides[$guideid];
    
    echo local_rtocompliance_render_nav_header($guide['title'], 'Practice Guides', '/local/rtocompliance/practice_guides.php');
    
    echo html_writer::start_div('compliance-container');

    echo html_writer::start_div('compliance-header');
    echo html_writer::tag('h2', pg_icon($guide['icon'], 'header-icon') . ' Practice Guide: ' . $guide['title']);
    echo html_writer::start_div('', ['style' => 'display:flex;gap:12px;flex-shrink:0;flex-wrap:wrap;']);
    echo html_writer::link(
        $guide['url'],
        pg_icon('external-link') . ' View on ASQA',
        ['class' => 'btn btn-outline-primary', 'target' => '_blank', 'style' => 'display:inline-flex;align-items:center;gap:8px;']
    );
    echo html_writer::link(
        $guide['pdf'],
        pg_icon('download') . ' Download PDF',
        ['class' => 'btn btn-primary', 'target' => '_blank', 'style' => 'display:inline-flex;align-items:center;gap:8px;']
    );
    echo html_writer::end_div();
    echo html_writer::end_div();

    echo html_writer::tag('p', 'Standards ' . $guide['standards'] . ' — ' . $guide['description'], ['class' => 'text-muted', 'style' => 'margin-bottom:1.5rem;']);
    echo html_writer::start_div('self-assurance-section');
    echo html_writer::tag('h3', 'Self-Assurance Questions & RTO Compliance Mapping', ['class' => 'section-heading']);
    echo html_writer::tag('p', 'Use these questions to evaluate your compliance. The right column shows how RTO Compliance helps you address each requirement.', ['class' => 'section-subtitle', 'style' => 'color: #6b7280; margin-bottom: 24px;']);
    
    echo '<div class="self-assurance-table">';
    echo '<div class="sa-header">';
    echo '<div class="sa-col-question">Self-Assurance Question</div>';
    echo '<div class="sa-col-mapping">How RTO Compliance Helps</div>';
    echo '</div>';
    
    foreach ($guide['questions'] as $index => $q) {
        echo '<div class="sa-row">';
        echo '<div class="sa-col-question">';
        echo '<span class="sa-number">' . ($index + 1) . '</span>';
        echo '<span class="sa-text">' . $q['q'] . '</span>';
        echo '</div>';
        echo '<div class="sa-col-mapping">';
        echo '<p class="sa-mapping-text">' . $q['mapping'] . '</p>';
        echo html_writer::link(
            new moodle_url($q['link']),
            'Open Module ' . pg_icon('arrow-right'),
            ['class' => 'sa-link']
        );
        echo '</div>';
        echo '</div>';
    }
    
    echo '</div>';
    echo html_writer::end_div(); // self-assurance-section

    echo html_writer::end_div(); // compliance-container

} else {
    echo local_rtocompliance_render_nav_header('Practice Guides');
    
    echo html_writer::start_div('compliance-container');

    echo html_writer::start_div('compliance-header');
    echo html_writer::tag('h2', pg_icon('book-open', 'header-icon') . ' ASQA Practice Guides');
    echo html_writer::end_div();

    echo html_writer::tag('p', 'Self-Assurance for Standards for RTOs 2025 — ASQA Practice Guides help you understand regulatory expectations under the 2025 Standards. Each guide includes self-assurance questions - use them to evaluate your compliance and see how RTO Compliance addresses each requirement.', ['class' => 'text-muted', 'style' => 'margin-bottom:1.5rem;']);
    
    $qualityAreas = [
        1 => ['title' => 'Quality Area 1: Training & Assessment', 'color' => 'amber', 'standards' => 'Standards 1.1-1.8'],
        2 => ['title' => 'Quality Area 2: VET Student Support', 'color' => 'blue', 'standards' => 'Standards 2.1-2.8'],
        3 => ['title' => 'Quality Area 3: VET Workforce', 'color' => 'green', 'standards' => 'Standards 3.1-3.3'],
        4 => ['title' => 'Quality Area 4: Governance', 'color' => 'purple', 'standards' => 'Standards 4.1-4.4'],
        5 => ['title' => 'Compliance Standards', 'color' => 'rose', 'standards' => 'Compliance Requirements'],
        6 => ['title' => 'Compliance Standards', 'color' => 'rose', 'standards' => ''],
        7 => ['title' => 'Compliance Standards', 'color' => 'rose', 'standards' => ''],
        8 => ['title' => 'Compliance Standards', 'color' => 'rose', 'standards' => ''],
        0 => ['title' => 'Credential Policy', 'color' => 'teal', 'standards' => 'Trainer & Assessor Credentials'],
    ];
    
    $groupedGuides = [];
    foreach ($practiceGuides as $id => $guide) {
        $qa = $guide['quality_area'];
        if ($qa >= 5 && $qa <= 8) $qa = 5;
        if (!isset($groupedGuides[$qa])) $groupedGuides[$qa] = [];
        $groupedGuides[$qa][$id] = $guide;
    }
    
    ksort($groupedGuides);
    if (isset($groupedGuides[0])) {
        $credential = $groupedGuides[0];
        unset($groupedGuides[0]);
        $groupedGuides[0] = $credential;
    }
    
    echo html_writer::start_div('practice-guides-grid');
    
    foreach ($groupedGuides as $qa => $guides) {
        if (empty($guides)) continue;
        
        $areaInfo = $qualityAreas[$qa] ?? ['title' => 'Other', 'color' => 'slate', 'standards' => ''];
        
        echo html_writer::start_div('practice-guide-category category-' . $areaInfo['color']);
        echo '<h4 class="category-title">' . $areaInfo['title'];
        if ($areaInfo['standards']) {
            echo '<br><span class="clause-ref">(' . $areaInfo['standards'] . ')</span>';
        }
        echo '</h4>';
        
        echo html_writer::start_div('practice-guide-cards');
        
        foreach ($guides as $id => $guide) {
            echo html_writer::start_tag('a', [
                'href' => new moodle_url('/local/rtocompliance/practice_guides.php', ['guide' => $id]),
                'class' => 'practice-guide-card'
            ]);
            echo '<div class="pg-card-icon">' . pg_icon($guide['icon']) . '</div>';
            echo '<div class="pg-card-content">';
            echo '<h5 class="pg-card-title">' . $guide['title'] . '</h5>';
            echo '<p class="pg-card-standards">Standards ' . $guide['standards'] . '</p>';
            echo '<p class="pg-card-desc">' . $guide['description'] . '</p>';
            echo '<span class="pg-card-questions">' . count($guide['questions']) . ' self-assurance questions</span>';
            echo '</div>';
            echo '<div class="pg-card-arrow">' . pg_icon('arrow-right') . '</div>';
            echo html_writer::end_tag('a');
        }
        
        echo html_writer::end_div();
        echo html_writer::end_div();
    }
    
    echo html_writer::end_div(); // practice-guides-grid

    echo html_writer::end_div(); // compliance-container
}

echo $OUTPUT->footer();
