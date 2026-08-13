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

class transition_form extends \moodleform {
    protected function definition() {
        global $CFG;
        $mform = $this->_form;
        $transition = $this->_customdata['transition'] ?? null;

        $mform->addElement('header', 'oldproduct', 'Superseded/Deleted Product');
        $mform->addHelpButton('oldproduct', 'transition_header', 'local_rtocompliance');

        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);

        $mform->addElement('text', 'oldproductcode', get_string('old_product_code', 'local_rtocompliance'), ['size' => 30, 'maxlength' => 30]);
        $mform->setType('oldproductcode', PARAM_ALPHANUMEXT);
        $mform->addRule('oldproductcode', get_string('required'), 'required', null, 'client');
        $mform->addHelpButton('oldproductcode', 'oldproductcode', 'local_rtocompliance');

        $mform->addElement('text', 'oldproductname', get_string('old_product_name', 'local_rtocompliance'), ['size' => 80, 'maxlength' => 255]);
        $mform->setType('oldproductname', PARAM_TEXT);
        $mform->addRule('oldproductname', get_string('required'), 'required', null, 'client');
        $mform->addHelpButton('oldproductname', 'oldproductname', 'local_rtocompliance');

        $typeoptions = [
            'superseded' => get_string('transition_superseded', 'local_rtocompliance'),
            'deleted'    => get_string('transition_type_deleted', 'local_rtocompliance'),
            'updated'    => get_string('transition_type_updated', 'local_rtocompliance'),
        ];
        $mform->addElement('select', 'transitiontype', get_string('transition_type', 'local_rtocompliance'), $typeoptions);
        $mform->addRule('transitiontype', get_string('required'), 'required', null, 'client');
        $mform->addHelpButton('transitiontype', 'transitiontype', 'local_rtocompliance');

        $mform->addElement('header', 'newproduct', 'Replacement Product');
        $mform->addHelpButton('newproduct', 'newproduct_header', 'local_rtocompliance');

        $mform->addElement('text', 'newproductcode', get_string('new_product_code', 'local_rtocompliance'), ['size' => 30, 'maxlength' => 30]);
        $mform->setType('newproductcode', PARAM_ALPHANUMEXT);
        $mform->addHelpButton('newproductcode', 'newproductcode', 'local_rtocompliance');

        $mform->addElement('text', 'newproductname', get_string('new_product_name', 'local_rtocompliance'), ['size' => 80, 'maxlength' => 255]);
        $mform->setType('newproductname', PARAM_TEXT);
        $mform->addHelpButton('newproductname', 'newproductname', 'local_rtocompliance');

        $mform->addElement('header', 'timeline', 'Transition Timeline');
        $mform->addHelpButton('timeline', 'timeline_header', 'local_rtocompliance');

        $mform->addElement('date_selector', 'tganotificationdate', get_string('tga_notification_date', 'local_rtocompliance'));
        $mform->addRule('tganotificationdate', get_string('required'), 'required', null, 'client');
        $mform->addHelpButton('tganotificationdate', 'tganotificationdate', 'local_rtocompliance');

        $mform->addElement('date_selector', 'teachoutdeadline', get_string('teachout_deadline', 'local_rtocompliance'));
        $mform->addRule('teachoutdeadline', get_string('required'), 'required', null, 'client');
        $mform->addHelpButton('teachoutdeadline', 'teachoutdeadline', 'local_rtocompliance');

        $mform->addElement('header', 'impactedstudents', 'Impacted Students');
        $mform->addHelpButton('impactedstudents', 'impactedstudents_header', 'local_rtocompliance');

        $mform->addElement('text', 'studentsaffected', get_string('students_affected', 'local_rtocompliance'), ['size' => 10]);
        $mform->setType('studentsaffected', PARAM_INT);
        $mform->setDefault('studentsaffected', 0);
        $mform->addHelpButton('studentsaffected', 'studentsaffected', 'local_rtocompliance');

        $mform->addElement('text', 'studentscontacted', get_string('students_contacted', 'local_rtocompliance'), ['size' => 10]);
        $mform->setType('studentscontacted', PARAM_INT);
        $mform->setDefault('studentscontacted', 0);
        $mform->addHelpButton('studentscontacted', 'studentscontacted', 'local_rtocompliance');

        $mform->addElement('header', 'transitionplan_section', 'Transition Plan');
        $mform->addHelpButton('transitionplan_section', 'transitionplan_header', 'local_rtocompliance');

        $mform->addElement('textarea', 'transitionplan', get_string('transition_plan', 'local_rtocompliance'), ['rows' => 6, 'cols' => 80]);
        $mform->setType('transitionplan', PARAM_RAW); // pipeline-ignore: PARAM_RAW -- rich-text/JSON field; sanitised before display or decoded immediately
        $mform->addHelpButton('transitionplan', 'transitionplan', 'local_rtocompliance');

        // FEAT-TRANSITION-AI (v4.4.69): AI Generate button for Transition Plan field.
        $mform->addElement('static', 'transitionplan_ai', '',
            '<div class="rtoc-ai-box">' .
            '<button type="button" id="rtoc-ai-transitionplan" class="btn btn-primary" data-target="id_transitionplan" data-context="transitionplan">' .
            '<i class="fa fa-magic" aria-hidden="true"></i> AI: Generate Transition Plan' .
            '</button>' .
            '<span id="rtoc-ai-transitionplan-status" class="rtoc-ai-status"></span>' .
            '<small class="rtoc-ai-hint d-block mt-1 text-muted">Uses the superseded/replacement qualification details, teach-out deadline and number of students affected to draft a compliant transition plan (Standard 1.12 of the Standards for RTOs 2025).</small>' .
            '</div>');

        $mform->addElement('text', 'mappingdocument', get_string('mapping_document', 'local_rtocompliance'), ['size' => 80, 'maxlength' => 255]);
        $mform->setType('mappingdocument', PARAM_TEXT);
        $mform->addHelpButton('mappingdocument', 'mappingdocument', 'local_rtocompliance');

        $mform->addElement('header', 'actionstaken', 'Actions Taken');
        $mform->addHelpButton('actionstaken', 'actionstaken_header', 'local_rtocompliance');

        $mform->addElement('advcheckbox', 'scopeupdated', get_string('scope_updated', 'local_rtocompliance'));
        $mform->setDefault('scopeupdated', 0);
        $mform->addHelpButton('scopeupdated', 'scopeupdated', 'local_rtocompliance');

        // Linked Moodle course — controls self-enrolment when enrolmentsclosed is toggled.
        global $DB;
        $courseoptions = [0 => get_string('none')];
        $courserows = $DB->get_records_menu('course', null, 'fullname ASC', 'id,fullname');
        foreach ($courserows as $cid => $cfullname) {
            if ($cid == 1) continue; // Skip site course.
            $courseoptions[(int)$cid] = format_string($cfullname);
        }
        $mform->addElement('select', 'linkedcourseid', get_string('transition_linkedcourse', 'local_rtocompliance'), $courseoptions);
        $mform->setType('linkedcourseid', PARAM_INT);
        $mform->setDefault('linkedcourseid', 0);
        $mform->addHelpButton('linkedcourseid', 'transition_linkedcourse', 'local_rtocompliance');

        $mform->addElement('advcheckbox', 'enrolmentsclosed', get_string('enrolments_closed', 'local_rtocompliance'));
        $mform->setDefault('enrolmentsclosed', 0);
        $mform->addHelpButton('enrolmentsclosed', 'enrolmentsclosed', 'local_rtocompliance');

        $statusoptions = [
            'identified' => 'Identified',
            'planning' => 'Planning',
            'in_progress' => 'In Progress',
            'completed' => 'Completed',
        ];
        $mform->addElement('select', 'status', get_string('status', 'local_rtocompliance'), $statusoptions);
        $mform->setDefault('status', 'identified');
        $mform->addHelpButton('status', 'transition_status', 'local_rtocompliance');

        $mform->addElement('header', 'additionalinfo', get_string('additional_information', 'local_rtocompliance'));

        $mform->addElement('textarea', 'notes', get_string('notes', 'local_rtocompliance'), ['rows' => 4, 'cols' => 80]);
        $mform->setType('notes', PARAM_RAW); // pipeline-ignore: PARAM_RAW -- rich-text/JSON field; sanitised before display or decoded immediately
        $mform->addHelpButton('notes', 'transition_notes', 'local_rtocompliance');

        $this->add_action_buttons(true, get_string('savechanges'));
    }
}
