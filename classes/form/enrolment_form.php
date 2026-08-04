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

namespace local_rtocompliance\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

use local_rtocompliance\avetmiss_codes;

class enrolment_form extends \moodleform {
    protected function definition() {
        global $DB;
        $mform = $this->_form;
        $studentid = $this->_customdata['studentid'] ?? 0;
        $enrolment = $this->_customdata['enrolment'] ?? null;

        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);

        $mform->addElement('hidden', 'studentid');
        $mform->setType('studentid', PARAM_INT);
        $mform->setDefault('studentid', $studentid);

        // BUG-SR-OUTCOME-SAVE-5 (v4.2.33): A customer's 1 May 2026 screenshot shows the
        // POST landing on the dashboard with a blue "No student was specified"
        // toast — the early-redirect at the top of student_enrolments.php fired
        // because $userid was 0 on POST.  Root cause: the form ONLY had hidden
        // inputs for id+studentid, relying on the URL query string (?userid=N
        // &action=edit&enrolid=M) attached to the form's action attribute to
        // round-trip the page-level params.  Some Moodle 4.5+ themes / page
        // layouts strip the query string from form actions when re-rendering
        // (e.g. Boost child themes that override moodleform's get_action_url),
        // so the POST URL becomes /student_enrolments.php with NO query string,
        // PHP sees $userid=0, and the early "select a student" redirect fires
        // before the save block ever runs.  The form then appears to silently
        // revert because the user is bounced back to the student list with the
        // info toast — easy to mistake for a save that didn't take effect.
        // Belt-and-braces fix: carry userid+action+enrolid in the POST BODY as
        // hidden inputs so optional_param() picks them up regardless of URL.
        $userid_default  = (int) ($this->_customdata['userid']  ?? 0);
        $action_default  =       ($this->_customdata['action']  ?? 'edit');
        $enrolid_default = (int) ($this->_customdata['enrolid'] ?? 0);

        $mform->addElement('hidden', 'userid');
        $mform->setType('userid', PARAM_INT);
        $mform->setDefault('userid', $userid_default);

        $mform->addElement('hidden', 'action');
        $mform->setType('action', PARAM_ALPHA);
        $mform->setDefault('action', $action_default);

        $mform->addElement('hidden', 'enrolid');
        $mform->setType('enrolid', PARAM_INT);
        $mform->setDefault('enrolid', $enrolid_default);

        $mform->addElement('header', 'programdetails', get_string('program', 'local_rtocompliance'));
        $mform->addHelpButton('programdetails', 'program_header', 'local_rtocompliance');

        // FIX: Load only visible courses that are linked to a Qual Builder unit or
        // flagged as nationally recognised, rather than every course on the site.
        // This avoids loading thousands of unrelated courses into a <select> on
        // large Moodle installations. Falls back to all visible courses if no
        // linked courses exist (covers fresh installs / legacy configurations).
        $dbman = $DB->get_manager();
        $qualunits_exists = $dbman->table_exists('local_rtocompliance_qualunits');
        $settings_exists  = $dbman->table_exists('local_rtocompliance_coursesettings');

        $linked_courses = [];
        if ($qualunits_exists || $settings_exists) {
            $parts = [];
            if ($qualunits_exists) {
                $parts[] = "SELECT DISTINCT c.id, c.fullname
                              FROM {course} c
                              JOIN {local_rtocompliance_qualunits} qu ON qu.courseid = c.id
                             WHERE c.visible = 1 AND c.id > 1";
            }
            if ($settings_exists) {
                $parts[] = "SELECT DISTINCT c.id, c.fullname
                              FROM {course} c
                              JOIN {local_rtocompliance_coursesettings} cs ON cs.courseid = c.id
                             WHERE c.visible = 1 AND c.id > 1
                               AND cs.nationallyrecognised = 1";
            }
            $union_sql = implode(' UNION ', $parts) . ' ORDER BY fullname';
            $linked_courses = $DB->get_records_sql($union_sql);
        }

        if (!empty($linked_courses)) {
            $courses = ['' => get_string('choosedots')];
            foreach ($linked_courses as $c) {
                $courses[$c->id] = $c->fullname;
            }
        } else {
            // Fallback: all visible non-site courses.
            $courses = $DB->get_records_menu('course', ['visible' => 1], 'fullname', 'id, fullname');
            $courses = ['' => get_string('choosedots')] + $courses;
        }

        // BUG-SR-OUTCOME-SAVE-4 (v4.2.32): when EDITING an existing enrolment, the
        // saved courseid may not be in $linked_courses (e.g. the qual builder
        // linkage was deleted, the course was unflagged as nationally recognised,
        // or the course was hidden/deleted).  When that happens the <select>
        // silently defaults to '' (Choose...) on render, and on save the form
        // validation `if (empty($data['courseid']))` fails — the form re-renders
        // with a tiny inline error on the courseid field that managers don't
        // notice while looking at Outcome / Status — producing the exact
        // "I changed Outcome to Competent and Status to Completed and clicked
        // Save and nothing happened" symptom reported across THREE login
        // roles (admin, teacher, manager) on 1 May 2026.  Always preserve the
        // currently-saved courseid in the dropdown so the form round-trips
        // cleanly even if the qual-builder linkage has since been removed.
        if (!empty($enrolment) && !empty($enrolment->courseid) && !isset($courses[$enrolment->courseid])) {
            $existingcourse = $DB->get_record('course', ['id' => $enrolment->courseid], 'id, fullname, shortname, visible', IGNORE_MISSING);
            if ($existingcourse) {
                $label = $existingcourse->fullname;
                if (empty($existingcourse->visible)) {
                    $label .= ' (hidden)';
                }
                $courses[$existingcourse->id] = $label;
            } else {
                // Course row truly missing — keep the FK value addressable so
                // the form can still POST it back unchanged on Save.
                $courses[$enrolment->courseid] = '[Course #' . (int)$enrolment->courseid . ' — not found]';
            }
        }

        $mform->addElement('select', 'courseid', get_string('course'), $courses);
        $mform->addRule('courseid', get_string('required'), 'required', null, 'client');
        $mform->addHelpButton('courseid', 'enrolment_courseid', 'local_rtocompliance');

        $mform->addElement('text', 'programcode', get_string('qualificationcode', 'local_rtocompliance'));
        $mform->setType('programcode', PARAM_ALPHANUMEXT);
        $mform->addHelpButton('programcode', 'programcode', 'local_rtocompliance');

        $mform->addElement('text', 'programname', get_string('qualificationname', 'local_rtocompliance'));
        $mform->setType('programname', PARAM_TEXT);
        $mform->addHelpButton('programname', 'programname', 'local_rtocompliance');

        $mform->addElement('header', 'unitdetails', get_string('unit', 'local_rtocompliance'));
        $mform->addHelpButton('unitdetails', 'unit_header', 'local_rtocompliance');

        $mform->addElement('text', 'unitcode', get_string('unitcode', 'local_rtocompliance'));
        $mform->setType('unitcode', PARAM_ALPHANUMEXT);
        $mform->addHelpButton('unitcode', 'unitcode', 'local_rtocompliance');

        $mform->addElement('text', 'unitname', get_string('unitname', 'local_rtocompliance'));
        $mform->setType('unitname', PARAM_TEXT);
        $mform->addHelpButton('unitname', 'unitname', 'local_rtocompliance');

        $mform->addElement('header', 'activitydetails', get_string('enrolment_details', 'local_rtocompliance'));
        $mform->addHelpButton('activitydetails', 'activity_header', 'local_rtocompliance');

        $mform->addElement('date_selector', 'activitystartdate', get_string('activitystartdate', 'local_rtocompliance'), ['optional' => true]);
        $mform->addHelpButton('activitystartdate', 'activitystartdate', 'local_rtocompliance');

        $mform->addElement('date_selector', 'activityenddate', get_string('activityenddate', 'local_rtocompliance'), ['optional' => true]);
        $mform->addHelpButton('activityenddate', 'activityenddate', 'local_rtocompliance');

        $mform->addElement('text', 'scheduledhours', get_string('scheduledhours', 'local_rtocompliance'));
        $mform->setType('scheduledhours', PARAM_INT);
        $mform->addHelpButton('scheduledhours', 'scheduledhours', 'local_rtocompliance');

        $outcomes = avetmiss_codes::get_outcome_identifiers();
        $mform->addElement('select', 'outcomeidentifier', get_string('outcome', 'local_rtocompliance'), $outcomes);
        // BUG-SR-OUTCOME FIX: '00' is not a valid AVETMISS outcome code — use '70' (Continuing Enrolment).
        $mform->setDefault('outcomeidentifier', '70');
        $mform->addHelpButton('outcomeidentifier', 'outcomeidentifier', 'local_rtocompliance');

        $deliverymodes = avetmiss_codes::get_delivery_mode_nat_codes();
        $mform->addElement('select', 'deliverymode', get_string('deliverymode', 'local_rtocompliance'), $deliverymodes);
        $mform->setDefault('deliverymode', '10');
        $mform->addHelpButton('deliverymode', 'deliverymode', 'local_rtocompliance');

        $mform->addElement('header', 'fundingdetails', get_string('fundingsource', 'local_rtocompliance'));
        $mform->addHelpButton('fundingdetails', 'funding_header', 'local_rtocompliance');

        $fundingsources = avetmiss_codes::get_funding_source_national_codes();
        $mform->addElement('select', 'fundingsourcenat', get_string('fundingsource', 'local_rtocompliance') . ' (National)', $fundingsources);
        $mform->setDefault('fundingsourcenat', '30');
        $mform->addHelpButton('fundingsourcenat', 'fundingsourcenat', 'local_rtocompliance');

        $mform->addElement('text', 'fundingsourcestate', get_string('fundingsource', 'local_rtocompliance') . ' (State)');
        $mform->setType('fundingsourcestate', PARAM_ALPHANUMEXT);
        $mform->addHelpButton('fundingsourcestate', 'fundingsourcestate', 'local_rtocompliance');

        $mform->addElement('text', 'tuitionfee', get_string('tuitionfee', 'local_rtocompliance'));
        $mform->setType('tuitionfee', PARAM_FLOAT);
        $mform->addHelpButton('tuitionfee', 'tuitionfee', 'local_rtocompliance');

        $feeoptions = avetmiss_codes::get_fee_charged_codes();
        $mform->addElement('select', 'feecharged', get_string('feecharged', 'local_rtocompliance'), $feeoptions);
        $mform->setDefault('feecharged', 'Y');
        $mform->addHelpButton('feecharged', 'feecharged', 'local_rtocompliance');

        $mform->addElement('text', 'govtcontribution', get_string('govtcontribution', 'local_rtocompliance'));
        $mform->setType('govtcontribution', PARAM_FLOAT);
        $mform->addHelpButton('govtcontribution', 'govtcontribution', 'local_rtocompliance');

        // ── State Funding Details ─────────────────────────────────────────────
        // These fields support state-specific AVETMISS "below the line" reporting
        // for QLD DTET, NSW Smart & Skilled, VIC Skills First, SA Skills
        // for All, WA DTWD, TAS Skills Tasmania, NT DITT, and ACT Skills Canberra.

        $concessioncodes = avetmiss_codes::get_concession_status_codes();
        $mform->addElement('select', 'concessionstatus', get_string('concessionstatus', 'local_rtocompliance'), $concessioncodes);
        $mform->setType('concessionstatus', PARAM_ALPHA);
        $mform->addHelpButton('concessionstatus', 'concessionstatus', 'local_rtocompliance');

        $mform->addElement('text', 'purchasingcontract1', get_string('purchasingcontract1', 'local_rtocompliance'), ['size' => 25, 'maxlength' => 20]);
        $mform->setType('purchasingcontract1', PARAM_ALPHANUMEXT);
        $mform->addHelpButton('purchasingcontract1', 'purchasingcontract1', 'local_rtocompliance');

        $mform->addElement('text', 'purchasingcontract2', get_string('purchasingcontract2', 'local_rtocompliance'), ['size' => 25, 'maxlength' => 20]);
        $mform->setType('purchasingcontract2', PARAM_ALPHANUMEXT);

        $mform->addElement('text', 'purchasingcontract3', get_string('purchasingcontract3', 'local_rtocompliance'), ['size' => 25, 'maxlength' => 20]);
        $mform->setType('purchasingcontract3', PARAM_ALPHANUMEXT);

        $mform->addElement('header', 'locationheader', get_string('deliverylocation', 'local_rtocompliance'));
        $mform->addHelpButton('locationheader', 'location_header', 'local_rtocompliance');

        $locations = ['' => get_string('choosedots')];
        // Gracefully handle missing locations table (for older installations)
        try {
            $dbman = $DB->get_manager();
            if ($dbman->table_exists('local_rtocompliance_locations')) {
                // Bug 31: Only show active locations. Inactive/closed locations should
                // not appear as valid choices in new enrolment forms.
                $locs = $DB->get_records('local_rtocompliance_locations', ['status' => 'active'], 'locationname', 'id, locationid, locationname');
                foreach ($locs as $loc) {
                    $locations[$loc->locationid] = $loc->locationid . ' - ' . $loc->locationname;
                }
            }
        } catch (\Exception $e) {
            // Table doesn't exist - show empty list with warning
            debugging('Locations table not found - please upgrade the plugin', DEBUG_DEVELOPER);
        }
        // BUG-SR-OUTCOME FIX: setType ensures empty selection becomes null (not empty string '')
        // which would violate nullable char(10) column semantics on strict DB configs.
        $mform->setType('deliverylocationid', PARAM_ALPHANUMEXT);
        $mform->addElement('select', 'deliverylocationid', get_string('deliverylocation', 'local_rtocompliance'), $locations);
        if (count($locations) <= 1) {
            $locationsurl = new \moodle_url('/local/rtocompliance/locations.php');
            $mform->addElement('static', 'location_hint', '',
                '<div class="alert alert-info" style="margin-top:4px;padding:6px 10px;font-size:0.85em;">' .
                get_string('location_list_empty_hint', 'local_rtocompliance',
                    \html_writer::link($locationsurl, get_string('delivery_locations', 'local_rtocompliance'))) .
                '</div>'
            );
        }
        $mform->addHelpButton('deliverylocationid', 'deliverylocationid', 'local_rtocompliance');

        $mform->addElement('text', 'trainingcontractid', get_string('trainingcontractid', 'local_rtocompliance'));
        $mform->setType('trainingcontractid', PARAM_ALPHANUMEXT);
        $mform->addHelpButton('trainingcontractid', 'trainingcontractid', 'local_rtocompliance');

        $assessors = ['' => get_string('none', 'local_rtocompliance')];
        // Gracefully handle missing trainers table (for older installations)
        try {
            $dbman = $DB->get_manager();
            if ($dbman->table_exists('local_rtocompliance_trainers')) {
                $trainers = $DB->get_records_sql(
                    "SELECT t.userid, u.firstname, u.lastname,
                            u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename
                     FROM {local_rtocompliance_trainers} t
                     JOIN {user} u ON u.id = t.userid
                     WHERE t.status IN ('active', 'current', 'expiring')
                     ORDER BY u.lastname, u.firstname"
                );
                foreach ($trainers as $t) {
                    $assessors[$t->userid] = fullname($t);
                }
            }
        } catch (\Exception $e) {
            debugging('Trainers table not found - please upgrade the plugin', DEBUG_DEVELOPER);
        }
        $mform->addElement('select', 'assessoruserid', get_string('assessor', 'local_rtocompliance'), $assessors);
        $mform->setType('assessoruserid', PARAM_INT);
        if (count($assessors) <= 1) {
            $trainersurl = new \moodle_url('/local/rtocompliance/trainers.php');
            $mform->addElement('static', 'assessor_hint', '',
                '<div class="alert alert-info" style="margin-top:4px;padding:6px 10px;font-size:0.85em;">' .
                get_string('assessor_list_empty_hint', 'local_rtocompliance',
                    \html_writer::link($trainersurl, get_string('trainer_register', 'local_rtocompliance'))) .
                '</div>'
            );
        }
        $mform->addHelpButton('assessoruserid', 'assessoruserid', 'local_rtocompliance');

        $mform->addElement('text', 'purchasedfrom', get_string('purchasedfrom', 'local_rtocompliance'));
        $mform->setType('purchasedfrom', PARAM_ALPHANUMEXT);
        $mform->addHelpButton('purchasedfrom', 'purchasedfrom', 'local_rtocompliance');

        $mform->addElement('header', 'vetoptions', get_string('vetoptions', 'local_rtocompliance'));
        $mform->addHelpButton('vetoptions', 'vetoptions_header', 'local_rtocompliance');

        $vetoptions = avetmiss_codes::get_vet_flag_codes();
        $mform->addElement('select', 'vetflag', get_string('vetflag', 'local_rtocompliance'), $vetoptions);
        $mform->setDefault('vetflag', 'Y');
        $mform->addHelpButton('vetflag', 'vetflag', 'local_rtocompliance');

        $vetisOptions = avetmiss_codes::get_at_school_flag_codes();
        $mform->addElement('select', 'vetinschoolsflag', get_string('vetinschoolsflag', 'local_rtocompliance'), $vetisOptions);
        $mform->setDefault('vetinschoolsflag', 'N');
        $mform->addHelpButton('vetinschoolsflag', 'vetinschoolsflag', 'local_rtocompliance');

        $commencingOptions = avetmiss_codes::get_commencing_program_codes();
        $mform->addElement('select', 'commencingprogramid', get_string('commencingprogramid', 'local_rtocompliance'), $commencingOptions);
        $mform->setDefault('commencingprogramid', '3');
        $mform->addHelpButton('commencingprogramid', 'commencingprogramid', 'local_rtocompliance');

        $mform->addElement('header', 'statusheader', get_string('enrolmentstatus', 'local_rtocompliance'));
        $mform->addHelpButton('statusheader', 'enrolmentstatus_header', 'local_rtocompliance');

        $statusoptions = [
            'active' => get_string('active', 'local_rtocompliance'),
            'completed' => get_string('completed', 'core'),
            'withdrawn' => get_string('withdrawn', 'local_rtocompliance'),
            'hold' => get_string('onhold', 'local_rtocompliance'),
        ];
        $mform->addElement('select', 'status', get_string('enrolmentstatus', 'local_rtocompliance'), $statusoptions);
        $mform->setDefault('status', 'active');
        $mform->addHelpButton('status', 'enrolment_status', 'local_rtocompliance');

        $mform->addElement('date_selector', 'holduntil', get_string('holduntil', 'local_rtocompliance'), ['optional' => true]);
        $mform->hideIf('holduntil', 'status', 'neq', 'hold');
        $mform->addHelpButton('holduntil', 'holduntil', 'local_rtocompliance');

        $mform->addElement('textarea', 'holdreason', get_string('holdreason', 'local_rtocompliance'), ['rows' => 3, 'cols' => 50]);
        $mform->setType('holdreason', PARAM_TEXT);
        $mform->hideIf('holdreason', 'status', 'neq', 'hold');
        $mform->addHelpButton('holdreason', 'holdreason', 'local_rtocompliance');

        $this->add_action_buttons();
    }

    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        if (empty($data['courseid'])) {
            $errors['courseid'] = get_string('required');
        }

        if (!empty($data['activitystartdate']) && !empty($data['activityenddate'])) {
            if ($data['activityenddate'] < $data['activitystartdate']) {
                $errors['activityenddate'] = get_string('error_endbeforestart', 'local_rtocompliance');
            }
        }

        if (!empty($data['scheduledhours']) && $data['scheduledhours'] < 0) {
            $errors['scheduledhours'] = get_string('error_invalidhours', 'local_rtocompliance');
        }

        return $errors;
    }
}
