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
 * RTO Compliance plugin — cricos_codes.php.
 *
 * @package    local_rtocompliance
 * @copyright  2025 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace local_rtocompliance;

defined('MOODLE_INTERNAL') || die();

class cricos_codes {
    public static function get_visa_subclasses() {
        return [
            '500' => 'Student Visa (Subclass 500)',
            '485' => 'Temporary Graduate Visa (Subclass 485)',
            '407' => 'Training Visa (Subclass 407)',
            '408' => 'Temporary Activity Visa (Subclass 408)',
            '417' => 'Working Holiday Visa (Subclass 417)',
            '462' => 'Work and Holiday Visa (Subclass 462)',
            '600' => 'Visitor Visa (Subclass 600)',
            '820' => 'Partner Visa (Subclass 820)',
            '801' => 'Partner Visa (Subclass 801)',
            '309' => 'Partner (Provisional) Visa (Subclass 309)',
            '100' => 'Partner (Migrant) Visa (Subclass 100)',
            '186' => 'Employer Nomination Scheme (Subclass 186)',
            '187' => 'Regional Sponsored Migration Scheme (Subclass 187)',
            '189' => 'Skilled Independent Visa (Subclass 189)',
            '190' => 'Skilled Nominated Visa (Subclass 190)',
            '491' => 'Skilled Work Regional (Provisional) (Subclass 491)',
            '494' => 'Skilled Employer Sponsored Regional (Subclass 494)',
            '590' => 'Student Guardian Visa (Subclass 590)',
        ];
    }

    public static function get_student_visa_conditions() {
        return [
            '8105' => 'Work limitation (max 48 hours per fortnight during study)',
            '8202' => 'Must maintain course enrolment and satisfactory progress',
            '8501' => 'Must maintain adequate health insurance (OSHC)',
            '8516' => 'Must continue to satisfy visa requirements',
            '8517' => 'Must continue to satisfy visa requirements (student guardian)',
            '8532' => 'Under 18 - must have welfare arrangements in place',
            '8533' => 'Must tell provider of Australian address within 7 days',
            '8534' => 'Cannot change education provider for 6 months',
        ];
    }

    public static function get_coe_statuses() {
        return [
            'issued' => 'Issued - Awaiting Commencement',
            'commenced' => 'Commenced - Student Currently Studying',
            'completed' => 'Completed - Course Successfully Finished',
            'cancelled' => 'Cancelled - Enrolment Terminated',
            'suspended' => 'Suspended - Temporarily On Hold',
            'deferred' => 'Deferred - Start Date Postponed',
            'transferred' => 'Transferred - Moved to Another Provider',
        ];
    }

    public static function get_scv_types() {
        return [
            'extension' => 'Course Extension - Duration Extended',
            'reduction' => 'Course Reduction - Early Completion',
            'suspension' => 'Study Suspension - Temporary Leave',
            'cancellation' => 'Enrolment Cancellation',
            'transfer' => 'Provider Transfer (Section 19 Release)',
            'intervention' => 'Academic Intervention Strategy',
            'deferral' => 'Course Deferral',
        ];
    }

    public static function get_scv_reason_codes() {
        return [
            'compassionate' => [
                'code' => 'COMP',
                'label' => 'Compassionate or Compelling Circumstances',
                'examples' => ['Serious illness', 'Family emergency', 'Political upheaval', 'Natural disaster'],
            ],
            'academic' => [
                'code' => 'ACAD',
                'label' => 'Academic Reasons',
                'examples' => ['Initial course too difficult', 'Failed prerequisites', 'Need additional study time'],
            ],
            'financial' => [
                'code' => 'FINA',
                'label' => 'Financial Hardship',
                'examples' => ['Unexpected financial difficulty', 'Loss of sponsor'],
            ],
            'medical' => [
                'code' => 'MEDI',
                'label' => 'Medical Condition',
                'examples' => ['Illness', 'Injury', 'Mental health concerns'],
            ],
            'misrepresentation' => [
                'code' => 'MISR',
                'label' => 'Misrepresentation',
                'examples' => ['Course did not match description', 'Agent provided false information'],
            ],
            'provider_default' => [
                'code' => 'PROV',
                'label' => 'Provider Default',
                'examples' => ['Course cancelled', 'Provider ceased operations'],
            ],
            'progress' => [
                'code' => 'PROG',
                'label' => 'Course Progress Issues',
                'examples' => ['Unsatisfactory progress', 'Failed units', 'At risk of visa cancellation'],
            ],
        ];
    }

    public static function get_reporting_timeframes() {
        return [
            'under_18' => [
                'days' => 14,
                'description' => 'Students under 18 years of age - 14 calendar days',
            ],
            'adult' => [
                'days' => 31,
                'description' => 'Students 18 years or older - 31 calendar days',
            ],
        ];
    }

    public static function get_reportable_events() {
        return [
            'enrolment' => [
                'label' => 'Student Acceptance/Enrolment',
                'description' => 'When a student accepts an offer and CoE is created',
                'prisms_action' => 'Create CoE',
            ],
            'non_commencement' => [
                'label' => 'Failure to Commence',
                'description' => 'Student does not start their course as expected',
                'prisms_action' => 'Report Non-Commencement',
            ],
            'termination_student' => [
                'label' => 'Termination by Student',
                'description' => 'Student withdraws from course',
                'prisms_action' => 'Cancel CoE - Student Initiated',
            ],
            'termination_provider' => [
                'label' => 'Termination by Provider',
                'description' => 'Provider terminates student enrolment',
                'prisms_action' => 'Cancel CoE - Provider Initiated',
            ],
            'course_variation' => [
                'label' => 'Course Variation',
                'description' => 'Change to course dates, duration or details',
                'prisms_action' => 'Create SCV',
            ],
            'address_change' => [
                'label' => 'Address/Contact Change',
                'description' => 'Student changes residential address or contact details',
                'prisms_action' => 'Update Student Details',
            ],
            'progress_unsatisfactory' => [
                'label' => 'Unsatisfactory Course Progress',
                'description' => 'Student at risk of not completing course in expected timeframe',
                'prisms_action' => 'Create SCV with Intervention',
            ],
            'attendance_breach' => [
                'label' => 'Attendance Breach',
                'description' => 'Student attendance falls below required threshold',
                'prisms_action' => 'Report Attendance Breach',
            ],
            'completion' => [
                'label' => 'Course Completion',
                'description' => 'Student successfully completes their course',
                'prisms_action' => 'Complete CoE',
            ],
            'transfer_release' => [
                'label' => 'Provider Transfer (Section 19)',
                'description' => 'Student transfers to another education provider',
                'prisms_action' => 'Release for Transfer',
            ],
        ];
    }

    public static function get_national_code_standards() {
        return [
            1 => [
                'title' => 'Marketing Information and Practices',
                'description' => 'Marketing information and practices must be accurate, not misleading, and allow students to make informed decisions',
                'key_requirements' => [
                    'Accurate course information',
                    'Honest marketing materials',
                    'Clear fees and refund policies',
                    'No false claims about outcomes',
                ],
            ],
            2 => [
                'title' => 'Recruitment of an Overseas Student',
                'description' => 'Ensure students are appropriately qualified for the course with sufficient English proficiency',
                'key_requirements' => [
                    'Entry requirements verification',
                    'English proficiency evidence',
                    'Genuine temporary entrant assessment',
                    'Prior learning recognition',
                ],
            ],
            3 => [
                'title' => 'Formalisation of Enrolment and Written Agreements',
                'description' => 'Written agreements before accepting fees covering obligations and refund arrangements',
                'key_requirements' => [
                    'Written agreement before payment',
                    'Clear fee structure',
                    'Refund policy included',
                    'Student obligations outlined',
                ],
            ],
            4 => [
                'title' => 'Education Agents',
                'description' => 'Manage education agents appropriately to ensure they act ethically',
                'key_requirements' => [
                    'Written agreement with agents',
                    'Agent performance monitoring',
                    'No corrupt agent practices',
                    'Agent list on website',
                ],
            ],
            5 => [
                'title' => 'Younger Overseas Students',
                'description' => 'Additional requirements for students under 18 including welfare arrangements',
                'key_requirements' => [
                    'CAAW letter arrangements',
                    'Accommodation arrangements',
                    'General welfare support',
                    'Critical incident protocols',
                ],
            ],
            6 => [
                'title' => 'Overseas Student Support Services',
                'description' => 'Support services to help students adjust and study successfully',
                'key_requirements' => [
                    'Orientation program',
                    'Academic support services',
                    'Welfare support services',
                    'Access to complaints process',
                ],
            ],
            7 => [
                'title' => 'Overseas Student Transfers',
                'description' => 'Restrictions on enrolling students transferring from another provider within 6 months',
                'key_requirements' => [
                    '6-month restriction period',
                    'Section 19 release process',
                    'Transfer policy documented',
                    'Record keeping of transfers',
                ],
            ],
            8 => [
                'title' => 'Overseas Student Visa Requirements',
                'description' => 'Ensure students meet attendance, progress, and full-time study requirements',
                'key_requirements' => [
                    '20 hours/week minimum',
                    'Course progress monitoring',
                    'Intervention strategies',
                    'PRISMS reporting obligations',
                ],
            ],
            9 => [
                'title' => 'Deferring, Suspending or Cancelling Enrolment',
                'description' => 'Process for deferring, suspending, or cancelling student enrolments',
                'key_requirements' => [
                    'Documented deferral/suspension policy',
                    'Compassionate circumstances process',
                    'Provider-initiated cancellation process',
                    'Appeals process',
                ],
            ],
            10 => [
                'title' => 'Complaints and Appeals',
                'description' => 'Access to fair and inexpensive complaints and appeals processes',
                'key_requirements' => [
                    'Internal complaints process',
                    'External appeals (OSO)',
                    'Documented procedures',
                    '20 working days for internal',
                ],
            ],
            11 => [
                'title' => 'Additional Registration Requirements',
                'description' => 'Additional CRICOS-specific requirements',
                'key_requirements' => [
                    'Notify changes to CRICOS',
                    'Display CRICOS details',
                    'Record keeping requirements',
                    'Annual declarations',
                ],
            ],
        ];
    }

    public static function get_oshc_providers() {
        return [
            'ahm' => 'ahm OSHC',
            'allianz' => 'Allianz Care Australia',
            'bupa' => 'BUPA Australia',
            'cbhs' => 'CBHS Corporate Health',
            'medibank' => 'Medibank Private',
            'nib' => 'nib OSHC',
            'other' => 'Other Provider',
        ];
    }

    public static function get_caaw_types() {
        return [
            'provider' => 'Provider Approved Welfare - Provider assumes responsibility',
            'parent' => 'Parent/Legal Guardian in Australia',
            'relative' => 'Nominated Relative (21+ years)',
            'dha_approved' => 'DHA Approved Relative/Other',
        ];
    }

    public static function get_delivery_modes() {
        return [
            'facetofaceattendance' => 'Face-to-face Attendance',
            'workplace' => 'Workplace-based',
            'mixed' => 'Mixed Mode (Face-to-face + Online)',
            'online' => 'Online (max 1/3 of course while onshore)',
        ];
    }

    public static function get_study_load_statuses() {
        return [
            'fulltime' => 'Full-time Study (20+ hours/week)',
            'reducedload' => 'Reduced Study Load (approved)',
            'suspended' => 'Study Suspended',
            'deferred' => 'Enrolment Deferred',
        ];
    }

    public static function get_progress_statuses() {
        return [
            'satisfactory' => [
                'label' => 'Satisfactory Progress',
                'description' => 'Student is on track to complete course in expected timeframe',
                'color' => 'success',
            ],
            'atrisk' => [
                'label' => 'At Risk',
                'description' => 'Student may not complete course in expected timeframe - intervention required',
                'color' => 'warning',
            ],
            'unsatisfactory' => [
                'label' => 'Unsatisfactory Progress',
                'description' => 'Student is not progressing satisfactorily - formal process initiated',
                'color' => 'danger',
            ],
        ];
    }

    public static function get_compliance_statuses() {
        return [
            'compliant' => [
                'label' => 'Compliant',
                'description' => 'Student is meeting all visa conditions and course requirements',
                'color' => 'success',
            ],
            'atrisk' => [
                'label' => 'At Risk of Breach',
                'description' => 'Student may be at risk of breaching visa conditions',
                'color' => 'warning',
            ],
            'breach' => [
                'label' => 'Potential Breach',
                'description' => 'Student may have breached visa conditions - investigation required',
                'color' => 'danger',
            ],
        ];
    }

    public static function get_intervention_types() {
        return [
            'counselling' => 'Academic Counselling',
            'studyplan' => 'Individual Study Plan',
            'tutoring' => 'Additional Tutoring Support',
            'reducedload' => 'Approved Reduced Study Load',
            'extension' => 'Assessment Extension',
            'warning' => 'Formal Warning Letter',
            'showreason' => 'Intention to Report/Show Cause Notice',
            'other' => 'Other Intervention',
        ];
    }

    public static function get_transfer_restrictions() {
        return [
            'period' => 6,
            'description' => 'Students cannot transfer from their principal course within first 6 months unless granted release',
            'release_reasons' => [
                'Government sponsor approval',
                'Provider ceased delivery',
                'Provider sanctions applied',
                'Letter of offer conditional on transfer',
                'Evidence of student mistreatment',
                'Compassionate or compelling circumstances',
            ],
        ];
    }

    public static function calculate_reporting_deadline($eventdate, $isunder18 = false) {
        $days = $isunder18 ? 14 : 31;
        return $eventdate + ($days * 24 * 60 * 60);
    }

    public static function get_days_until_deadline($deadlinedate) {
        $now = time();
        $diff = $deadlinedate - $now;
        return max(0, floor($diff / (24 * 60 * 60)));
    }

    public static function is_overdue($deadlinedate) {
        return time() > $deadlinedate;
    }

    public static function validate_coe_number($coenumber) {
        $coenumber = strtoupper(trim($coenumber));
        
        if (empty($coenumber)) {
            return ['valid' => false, 'error' => 'CoE number is required'];
        }
        
        if (!preg_match('/^[A-Z0-9]{10,15}$/', $coenumber)) {
            return ['valid' => false, 'error' => 'CoE number must be 10-15 alphanumeric characters'];
        }
        
        return ['valid' => true, 'coenumber' => $coenumber];
    }

    public static function validate_cricos_code($code) {
        $code = strtoupper(trim($code));
        
        if (empty($code)) {
            return ['valid' => false, 'error' => 'CRICOS course code is required'];
        }
        
        if (!preg_match('/^[0-9]{5,6}[A-Z]$/', $code)) {
            return ['valid' => false, 'error' => 'CRICOS course code must be 5-6 digits followed by a letter (e.g., 012345A)'];
        }
        
        return ['valid' => true, 'code' => $code];
    }

    public static function validate_passport_number($passportnumber, $country = null) {
        $passportnumber = strtoupper(trim($passportnumber));
        
        if (empty($passportnumber)) {
            return ['valid' => false, 'error' => 'Passport number is required'];
        }
        
        if (strlen($passportnumber) < 6 || strlen($passportnumber) > 20) {
            return ['valid' => false, 'error' => 'Passport number must be between 6 and 20 characters'];
        }
        
        if (!preg_match('/^[A-Z0-9]+$/', $passportnumber)) {
            return ['valid' => false, 'error' => 'Passport number can only contain letters and numbers'];
        }
        
        return ['valid' => true, 'passportnumber' => $passportnumber];
    }

    public static function get_attendance_threshold() {
        return [
            'minimum_hours' => 20,
            'warning_threshold' => 85,
            'breach_threshold' => 80,
            'description' => 'Students must maintain at least 80% attendance with minimum 20 scheduled hours per week',
        ];
    }

    public static function get_progress_thresholds() {
        return [
            'satisfactory' => 50,
            'at_risk' => 50,
            'unsatisfactory' => 50,
            'description' => 'Students must pass at least 50% of units attempted in a study period',
            'consecutive_failures' => 2,
        ];
    }

    public static function get_record_keeping_periods() {
        return [
            'student_records' => [
                'years' => 2,
                'description' => '2 years after student ceases enrolment',
            ],
            'assessment_records' => [
                'years' => 2,
                'description' => '2 years after student ceases enrolment',
            ],
            'completed_assessments' => [
                'months' => 6,
                'description' => '6 months from competency judgement date',
            ],
            'financial_records' => [
                'years' => 7,
                'description' => '7 years for financial/tax records',
            ],
        ];
    }

    public static function get_annual_obligations() {
        return [
            'feb28' => [
                'title' => 'Total VET Activity (TVA) Submission',
                'description' => 'Submit AVETMISS data for previous calendar year',
            ],
            'mar31' => [
                'title' => 'Annual Declaration on Compliance',
                'description' => 'Submit annual declaration confirming continued compliance',
            ],
            'jul31' => [
                'title' => 'Annual Registration Charge',
                'description' => 'Pay CRICOS annual registration charge',
            ],
            'ongoing' => [
                'title' => 'PRISMS Reporting',
                'description' => 'Report events within 14/31 days',
            ],
            'renewal' => [
                'title' => 'CRICOS Registration Renewal',
                'description' => 'Apply for renewal 90 days before expiry',
            ],
        ];
    }

    public static function get_fee_restrictions() {
        return [
            'upfront_limit' => [
                'percentage' => 50,
                'description' => 'Cannot receive more than 50% of tuition fees before course commencement',
            ],
            'short_course_exception' => [
                'weeks' => 25,
                'description' => 'Courses less than 25 weeks may collect full fees upfront',
            ],
        ];
    }

    public static function format_reporting_event_summary($event) {
        $events = self::get_reportable_events();
        $eventinfo = $events[$event['eventtype']] ?? null;
        
        if (!$eventinfo) {
            return 'Unknown event type';
        }
        
        $deadline = self::calculate_reporting_deadline(
            $event['eventdate'],
            $event['isunder18'] ?? false
        );
        
        $daysremaining = self::get_days_until_deadline($deadline);
        $isoverdue = self::is_overdue($deadline);
        
        return [
            'event' => $eventinfo['label'],
            'action_required' => $eventinfo['prisms_action'],
            'deadline' => $deadline,
            'days_remaining' => $daysremaining,
            'is_overdue' => $isoverdue,
            'urgency' => $isoverdue ? 'overdue' : ($daysremaining <= 7 ? 'urgent' : 'normal'),
        ];
    }
}
