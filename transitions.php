<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/lib.php');

admin_externalpage_setup('local_rtocompliance_transitions');
$PAGE->set_title(get_string('transitions', 'local_rtocompliance'));
$PAGE->set_heading(get_string('transitions', 'local_rtocompliance'));

$PAGE->requires->css('/local/rtocompliance/styles.css');

$PAGE->add_body_class("path-local-rtocompliance");
echo $OUTPUT->header();
echo local_rtocompliance_render_nav_header(get_string('transitions', 'local_rtocompliance'), null, null, 'transitions');

echo html_writer::start_div('compliance-container');

echo html_writer::start_div('compliance-header');
echo html_writer::tag('h2', 'Training Product Transitions');
echo html_writer::link(
    new moodle_url('/local/rtocompliance/transition_edit.php'),
    'Add Transition Plan',
    ['class' => 'btn btn-primary']
);
echo html_writer::end_div();

echo html_writer::start_div('info-card');
echo html_writer::tag('h4', 'Superseded & Deleted Products');
echo html_writer::tag('p', 'Track training products that are superseded or deleted from training.gov.au. Manage student transition planning, teach-out arrangements, and enrolment controls to ensure students complete qualifications before expiry. Link a Moodle course to each transition plan to automatically disable self-enrolment when enrolments are closed — preventing new students from enrolling into superseded qualifications.');
echo html_writer::end_div();

$transitions = [];
if ($DB->get_manager()->table_exists('local_rtocompliance_transitions')) {
    $transitions = $DB->get_records('local_rtocompliance_transitions', null, 'teachoutdeadline ASC');
}

// Pre-fetch linked course names for display.
$linkedcoursenames = [];
if ($transitions) {
    $linkedids = array_filter(array_column((array)$transitions, 'linkedcourseid'));
    if ($linkedids) {
        $rows = $DB->get_records_list('course', 'id', array_unique($linkedids), '', 'id,fullname,shortname');
        foreach ($rows as $c) {
            $linkedcoursenames[$c->id] = format_string($c->fullname) . ' (' . $c->shortname . ')';
        }
    }
}

if ($transitions) {
    echo html_writer::start_div('rtoc-table-scroll');
    echo html_writer::start_tag('table', ['class' => 'data-table']);
    echo html_writer::start_tag('thead');
    echo html_writer::start_tag('tr');
    echo html_writer::tag('th', 'Old Product');
    echo html_writer::tag('th', 'New Product');
    echo html_writer::tag('th', 'Type');
    echo html_writer::tag('th', 'Teach-Out Deadline');
    echo html_writer::tag('th', 'Students Affected');
    echo html_writer::tag('th', 'Status');
    echo html_writer::tag('th', 'Enrolments');
    echo html_writer::tag('th', 'Actions');
    echo html_writer::end_tag('tr');
    echo html_writer::end_tag('thead');
    echo html_writer::start_tag('tbody');

    foreach ($transitions as $trans) {
        $daysuntil = ceil(($trans->teachoutdeadline - time()) / 86400);
        $statusclass = 'badge-info';
        $status = ucfirst(str_replace('_', ' ', $trans->status));

        if ($trans->status == 'completed') {
            $statusclass = 'badge-success';
        } elseif ($daysuntil < 0) {
            $statusclass = 'badge-danger';
            $status = 'Overdue';
        } elseif ($daysuntil < 90) {
            $statusclass = 'badge-warning';
            $status = $daysuntil . ' days left';
        }

        $typeclass = $trans->transitiontype == 'superseded' ? 'badge-info' : 'badge-danger';

        // -----------------------------------------------------------------------
        // Enrolment status cell — show whether new self-enrolments are blocked in
        // Moodle (via the linked course) or just flagged as closed manually.
        // -----------------------------------------------------------------------
        $linkedcourseid = !empty($trans->linkedcourseid) ? (int)$trans->linkedcourseid : 0;
        if ($linkedcourseid && isset($linkedcoursenames[$linkedcourseid])) {
            $coursename = $linkedcoursenames[$linkedcourseid];
            // Check actual Moodle self-enrolment status on the linked course.
            $selfenrols = $DB->get_records('enrol', ['courseid' => $linkedcourseid, 'enrol' => 'self']);
            $hasopen = false;
            foreach ($selfenrols as $se) { if ($se->status == 0) { $hasopen = true; break; } }

            if ($trans->enrolmentsclosed) {
                $enrollabel  = html_writer::tag('span', '&#10003; Closed in Moodle', ['class' => 'badge badge-success', 'title' => 'Self-enrolment disabled on: ' . $coursename]);
            } elseif ($daysuntil < 0) {
                // Past deadline and enrolments still open — highlight as a risk.
                $enrollabel = html_writer::tag('span', '&#9888; Still Open', ['class' => 'badge badge-danger', 'title' => 'Teach-out deadline passed but self-enrolment is still open on: ' . $coursename]);
            } else {
                $enrollabel = html_writer::tag('span', 'Open', ['class' => 'badge badge-secondary', 'title' => 'Self-enrolment is open on: ' . $coursename]);
            }
            $courseinfo = html_writer::tag('small', $coursename, ['style' => 'display:block;color:#6b7280;margin-top:2px;']);
            $enrolcell = $enrollabel . $courseinfo;
        } else {
            // No linked course — show manual flag only.
            if ($trans->enrolmentsclosed) {
                $enrolcell = html_writer::tag('span', 'Closed (manual)', ['class' => 'badge badge-secondary',
                    'title' => 'Marked as closed — link a Moodle course in Manage to enforce this in Moodle']);
            } elseif ($daysuntil < 0) {
                $enrolcell = html_writer::tag('span', '&#9888; No Moodle control', ['class' => 'badge badge-warning',
                    'title' => 'Teach-out deadline passed — link a Moodle course in Manage to block new enrolments']);
            } else {
                $enrolcell = html_writer::tag('span', '—', []);
            }
        }

        // Old product cell — show linked course under the product code if set.
        $oldproductcell = html_writer::tag('code', $trans->oldproductcode) . '<br>' . html_writer::tag('small', format_string($trans->oldproductname));

        echo html_writer::start_tag('tr');
        echo html_writer::tag('td', $oldproductcell);
        echo html_writer::tag('td', $trans->newproductcode ? html_writer::tag('code', $trans->newproductcode) . '<br>' . html_writer::tag('small', format_string($trans->newproductname)) : '-');
        echo html_writer::tag('td', html_writer::tag('span', ucfirst($trans->transitiontype), ['class' => 'badge ' . $typeclass]));
        echo html_writer::tag('td', userdate($trans->teachoutdeadline, '%d %b %Y'));
        echo html_writer::tag('td', $trans->studentsaffected);
        echo html_writer::tag('td', html_writer::tag('span', $status, ['class' => 'badge ' . $statusclass]));
        echo html_writer::tag('td', $enrolcell);
        echo html_writer::tag('td',
            html_writer::link(
                new moodle_url('/local/rtocompliance/transition_edit.php', ['id' => $trans->id]),
                'Manage',
                ['class' => 'btn btn-sm btn-secondary']
            )
        );
        echo html_writer::end_tag('tr');
    }

    echo html_writer::end_tag('tbody');
    echo html_writer::end_tag('table');
    echo html_writer::end_div();
} else {
    echo html_writer::start_div('empty-state');
    echo $OUTPUT->pix_icon('i/switchrole', '', 'moodle', ['class' => 'empty-state-icon']);
    echo html_writer::tag('h3', 'No Active Transitions');
    echo html_writer::tag('p', 'When training products on your scope are superseded or deleted, create transition plans here to manage teach-out and student transfers.');
    echo html_writer::link(
        new moodle_url('/local/rtocompliance/transition_edit.php'),
        'Add Transition Plan',
        ['class' => 'btn btn-primary']
    );
    echo html_writer::end_div();
}

echo html_writer::end_div();

echo $OUTPUT->footer();
