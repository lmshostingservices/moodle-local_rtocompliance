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

require_once(__DIR__ . '/../../config.php');
require_login();
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->libdir . '/formslib.php');
require_once(__DIR__ . '/lib.php');

admin_externalpage_setup('local_rtocompliance_dashboard');
$context = context_system::instance();
require_capability('moodle/site:config', $context);
$context = context_system::instance();

$action = optional_param('action', '', PARAM_ALPHA);
$id = optional_param('id', 0, PARAM_INT);

$PAGE->set_url('/local/rtocompliance/deadlines.php');
$PAGE->set_title(get_string('upcoming_deadlines', 'local_rtocompliance'));
$PAGE->set_heading(get_string('upcoming_deadlines', 'local_rtocompliance'));


if ($action === 'complete' && $id && confirm_sesskey()) {
    $deadline = $DB->get_record('local_rtocompliance_deadlines', ['id' => $id], '*', MUST_EXIST);
    $deadline->status = 'completed';
    $deadline->completedby = $USER->id;
    $deadline->completeddate = time();
    $deadline->timemodified = time();

    if ($deadline->recurring && $deadline->recurringperiod) {
        $newduedate = $deadline->duedate;
        switch ($deadline->recurringperiod) {
            case 'yearly':
                $newduedate = strtotime('+1 year', $deadline->duedate);
                break;
            case 'quarterly':
                $newduedate = strtotime('+3 months', $deadline->duedate);
                break;
            case 'monthly':
                $newduedate = strtotime('+1 month', $deadline->duedate);
                break;
        }

        $newdeadline = clone $deadline;
        unset($newdeadline->id);
        $newdeadline->duedate = $newduedate;
        $newdeadline->title = preg_replace('/\d{4}/', date('Y', $newduedate), $deadline->title);
        $newdeadline->status = 'pending';
        $newdeadline->completedby = null;
        $newdeadline->completeddate = null;
        $newdeadline->timecreated = time();
        $newdeadline->timemodified = time();
        $DB->insert_record('local_rtocompliance_deadlines', $newdeadline);
    }

    $DB->update_record('local_rtocompliance_deadlines', $deadline);
    redirect($PAGE->url, 'Deadline marked as completed', null, \core\output\notification::NOTIFY_SUCCESS);
}

if ($action === 'delete' && $id && confirm_sesskey()) {
    $DB->delete_records('local_rtocompliance_deadlines', ['id' => $id]);
    redirect($PAGE->url, 'Deadline deleted', null, \core\output\notification::NOTIFY_SUCCESS);
}

class deadline_form extends moodleform {
    protected function definition() {
        $mform = $this->_form;

        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);

        $mform->addElement('text', 'title', 'Title', ['size' => 60]);
        $mform->setType('title', PARAM_TEXT);
        $mform->addRule('title', null, 'required', null, 'client');

        $mform->addElement('textarea', 'description', 'Description', ['rows' => 2, 'cols' => 60]);
        $mform->setType('description', PARAM_TEXT);

        $types = [
            'tva' => 'TVA Submission',
            'qi' => 'Quality Indicator Data',
            'declaration' => 'Annual Declaration',
            'audit' => 'Audit',
            'other' => 'Other',
        ];
        $mform->addElement('select', 'deadlinetype', 'Type', $types);

        $mform->addElement('date_selector', 'duedate', 'Due Date');
        $mform->addRule('duedate', null, 'required', null, 'client');

        $mform->addElement('text', 'reminderdays', 'Reminder Days Before', ['size' => 5]);
        $mform->setType('reminderdays', PARAM_INT);
        $mform->setDefault('reminderdays', 30);

        $mform->addElement('advcheckbox', 'recurring', 'Recurring', 'This deadline repeats');

        $periods = [
            'yearly' => 'Yearly',
            'quarterly' => 'Quarterly',
            'monthly' => 'Monthly',
        ];
        $mform->addElement('select', 'recurringperiod', 'Repeat Period', $periods);
        $mform->disabledIf('recurringperiod', 'recurring', 'notchecked');

        $this->add_action_buttons(true, 'Save Deadline');
    }
}

// Check if table exists
$tableexists = $DB->get_manager()->table_exists('local_rtocompliance_deadlines');

$form = new deadline_form();

if ($form->is_cancelled()) {
    redirect($PAGE->url);
} elseif ($data = $form->get_data()) {
    if (!$tableexists) {
        redirect($PAGE->url, 'Deadline table not yet created. Please upgrade the plugin or run Moodle upgrade.', null, \core\output\notification::NOTIFY_ERROR);
    }
    $record = new stdClass();
    $record->deadlinetype = $data->deadlinetype;
    $record->title = $data->title;
    $record->description = $data->description ?? '';
    $record->duedate = $data->duedate;
    $record->reminderdays = $data->reminderdays;
    $record->recurring = $data->recurring ?? 0;
    $record->recurringperiod = $data->recurring ? ($data->recurringperiod ?? 'yearly') : null;
    $record->status = 'pending';
    $record->timemodified = time();

    if (!empty($data->id)) {
        $record->id = $data->id;
        $DB->update_record('local_rtocompliance_deadlines', $record);
    } else {
        $record->timecreated = time();
        $DB->insert_record('local_rtocompliance_deadlines', $record);
    }

    redirect($PAGE->url, 'Deadline saved', null, \core\output\notification::NOTIFY_SUCCESS);
}

$PAGE->add_body_class("path-local-rtocompliance");
echo $OUTPUT->header();
echo local_rtocompliance_render_nav_header(get_string('upcoming_deadlines', 'local_rtocompliance'));

echo html_writer::start_div('deadlines-container');

echo html_writer::start_div('compliance-header');
echo html_writer::tag('h2', get_string('upcoming_deadlines', 'local_rtocompliance'));
echo html_writer::end_div();

$deadlines = [];
if ($tableexists) {
    $deadlines = $DB->get_records('local_rtocompliance_deadlines', ['status' => 'pending'], 'duedate ASC');
}

if ($deadlines) {
    echo html_writer::start_tag('table', ['class' => 'table']);
    echo html_writer::start_tag('thead');
    echo html_writer::start_tag('tr');
    echo html_writer::tag('th', 'Deadline');
    echo html_writer::tag('th', 'Type');
    echo html_writer::tag('th', 'Due Date');
    echo html_writer::tag('th', 'Days Left');
    echo html_writer::tag('th', '');
    echo html_writer::end_tag('tr');
    echo html_writer::end_tag('thead');
    echo html_writer::start_tag('tbody');

    foreach ($deadlines as $deadline) {
        $daysuntil = ceil(($deadline->duedate - time()) / 86400);
        $statusclass = $daysuntil <= 0 ? 'status-urgent' : ($daysuntil <= 7 ? 'status-urgent' : ($daysuntil <= 30 ? 'status-warning' : 'status-ok'));

        echo html_writer::start_tag('tr');
        echo html_writer::tag('td',
            html_writer::tag('strong', format_string($deadline->title)) .
            ($deadline->description ? html_writer::empty_tag('br') . html_writer::tag('small', $deadline->description, ['class' => 'text-muted']) : '')
        );
        echo html_writer::tag('td', html_writer::tag('span', ucfirst($deadline->deadlinetype), ['class' => 'status-badge status-ok']));
        echo html_writer::tag('td', userdate($deadline->duedate, '%d %b %Y'));
        echo html_writer::tag('td', html_writer::tag('span', ($daysuntil <= 0 ? 'Overdue' : $daysuntil . ' days'), ['class' => 'status-badge ' . $statusclass]));
        echo html_writer::tag('td',
            html_writer::link(
                new moodle_url('/local/rtocompliance/deadlines.php', ['action' => 'complete', 'id' => $deadline->id, 'sesskey' => sesskey()]),
                'Complete',
                ['class' => 'btn btn-sm btn-primary']
            ) . ' ' .
            html_writer::link(
                new moodle_url('/local/rtocompliance/deadlines.php', ['action' => 'delete', 'id' => $deadline->id, 'sesskey' => sesskey()]),
                'Delete',
                ['class' => 'btn btn-sm btn-secondary', 'onclick' => "return confirm('Delete this deadline?');"]
            )
        );
        echo html_writer::end_tag('tr');
    }

    echo html_writer::end_tag('tbody');
    echo html_writer::end_tag('table');
}

echo html_writer::tag('h3', 'Add New Deadline', ['class' => 'section-title']);
$form->display();

echo html_writer::end_div();

echo $OUTPUT->footer();
