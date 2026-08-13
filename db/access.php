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
 * local_rtocompliance file.
 *
 * @package    local_rtocompliance
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// ROLE-SPLIT (v4.2.30, 30 Apr 2026): the previous capability map granted
// :manage and :viewall to BOTH manager AND editingteacher archetypes — meaning
// trainers / assessors saw and could modify the FULL compliance dashboard
// including Governance & ADC, Fee Protection, Insurance Register, Third-Party
// Arrangements, NAT export, AI Survey Analysis (management-level analytics)
// and other RTOs' student records.  This was fine when there was a single
// site-admin user but does NOT survive contact with a real RTO that has 15+
// trainers who must NOT see the financial registers or other trainers'
// personal data.  v4.2.30 splits the model into three role tiers:
//
//   1. siteadmin / manager  — full :manage + :viewall (governance, financials,
//                              all students, NAT export, AI analytics)
//   2. editingteacher       — :viewtrainer ONLY (own classes, own students'
//                              competency, own surveys, own currency profile,
//                              read-only Validation Schedule for events they
//                              are assigned to)
//   3. user (any logged-in)  — :viewown / :editownprofile (own USI / cert /
//                              currency profile)
//
// This is the same role split used by aXcelerate / Wisenet / VETtrak.
$capabilities = [
    'local/rtocompliance:manage' => [
        'riskbitmask' => RISK_CONFIG,
        'captype' => 'write',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes' => [
            'manager' => CAP_ALLOW,
            // v4.2.30 ROLE-SPLIT: editingteacher REMOVED — trainers should not
            // be able to write to compliance registers; they get :viewtrainer
            // for the trainer-scoped dashboard instead.
        ],
    ],
    'local/rtocompliance:viewall' => [
        'riskbitmask' => RISK_PERSONAL,
        'captype' => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [
            'manager' => CAP_ALLOW,
            // v4.2.30 ROLE-SPLIT: editingteacher REMOVED — :viewall reads
            // ALL students across ALL programs which is a privacy breach for
            // a trainer who should only see their own classes.
        ],
    ],
    // v4.2.30 ROLE-SPLIT: NEW capability — trainer-scoped read access to the
    // Trainer Dashboard, own students, own surveys, own currency profile, and
    // read-only Validation Schedule for events they're assigned to.
    'local/rtocompliance:viewtrainer' => [
        'riskbitmask' => RISK_PERSONAL,
        'captype' => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [
            'manager' => CAP_ALLOW,        // managers can view what trainers see too
            'editingteacher' => CAP_ALLOW, // primary intended audience
            'teacher' => CAP_ALLOW,        // non-editing teachers also legitimate trainers/assessors
        ],
    ],
    'local/rtocompliance:viewown' => [
        'captype' => 'read',
        'contextlevel' => CONTEXT_USER,
        'archetypes' => [
            'user' => CAP_ALLOW,
        ],
    ],
    'local/rtocompliance:issuecerts' => [
        'riskbitmask' => RISK_DATALOSS,
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [
            'manager' => CAP_ALLOW,
        ],
    ],
    'local/rtocompliance:managetrainers' => [
        'riskbitmask' => RISK_PERSONAL,
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [
            'manager' => CAP_ALLOW,
        ],
    ],
    'local/rtocompliance:exportnat' => [
        'riskbitmask' => RISK_PERSONAL,
        'captype' => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [
            'manager' => CAP_ALLOW,
        ],
    ],
    'local/rtocompliance:managesurveys' => [
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [
            'manager' => CAP_ALLOW,
            // v4.2.30 ROLE-SPLIT: editingteacher REMOVED — sending surveys is
            // a management function (cohort selection, AI analysis credits).
            // Trainers can VIEW their own classes' survey responses through
            // the Trainer Dashboard but should not be able to mass-send.
        ],
    ],
    'local/rtocompliance:editownprofile' => [
        'captype' => 'write',
        'contextlevel' => CONTEXT_USER,
        'archetypes' => [
            'user' => CAP_ALLOW,
        ],
    ],
    'local/rtocompliance:viewcerts' => [
        'riskbitmask' => RISK_PERSONAL,
        'captype' => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [
            'manager' => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
        ],
    ],
    'local/rtocompliance:viewstudents' => [
        'riskbitmask' => RISK_PERSONAL,
        'captype' => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [
            'manager' => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
        ],
    ],

    // FIX-VIEWREPORTS-CAP (v5.9.276): local/rtocompliance:viewreports was
    // referenced in db/services.php as the required capability for all four
    // external web-service functions (tga_get_builder_data,
    // get_courses_for_category, get_qual_settings, save_qual_settings) but
    // was NEVER declared here.  Moodle would throw "Capability not found"
    // for every API call, silently breaking the entire Qualbuilder TGA/course
    // lookup system for all users including managers.  Fixed by declaring the
    // capability with the same risk/context as :viewall (reads structured data,
    // no writes) and granting it to managers.
    'local/rtocompliance:viewreports' => [
        'riskbitmask' => RISK_PERSONAL,
        'captype' => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [
            'manager' => CAP_ALLOW,
        ],
    ],

    // CERT-TEMPLATE-BUILDER (v4.2.40):
    // Manage certificate templates — create, edit, submit-for-approval,
    // activate, archive.  Restricted to managers because activating a
    // template changes the look of every certificate the RTO issues.
    'local/rtocompliance:managecerttemplates' => [
        'riskbitmask' => RISK_CONFIG,
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [
            'manager' => CAP_ALLOW,
        ],
    ],
];
