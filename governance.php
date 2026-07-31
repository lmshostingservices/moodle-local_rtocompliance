<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/lib.php');

admin_externalpage_setup('local_rtocompliance_governancepage');

$tab = optional_param('tab', 'persons', PARAM_ALPHAEXT);

$PAGE->set_url('/local/rtocompliance/governance.php', ['tab' => $tab]);
$PAGE->set_title(get_string('governance', 'local_rtocompliance'));
$PAGE->set_heading(get_string('governance', 'local_rtocompliance'));

$PAGE->requires->css('/local/rtocompliance/styles.css');

$PAGE->add_body_class("path-local-rtocompliance");
echo $OUTPUT->header();
echo local_rtocompliance_render_nav_header(get_string('governance', 'local_rtocompliance'), null, null, 'governance');

echo html_writer::start_div('compliance-container');

echo html_writer::start_div('compliance-header');
echo html_writer::tag('h2', 'Leadership & Accountability — Governance Register');

$addBtnLabels = [
    'persons'  => 'Add Governing Person',
    'changes'  => 'Record Material Change',
    'adc'      => 'Start ADC Submission',
    'roles'    => 'Add Role',
    'minutes'  => 'Add Meeting Minutes',
];
$addBtnUrls = [
    'persons'  => new moodle_url('/local/rtocompliance/governance_edit.php', ['type' => 'persons']),
    'changes'  => new moodle_url('/local/rtocompliance/governance_edit.php', ['type' => 'changes']),
    'adc'      => new moodle_url('/local/rtocompliance/governance_edit.php', ['type' => 'adc']),
    'roles'    => new moodle_url('/local/rtocompliance/governance_roles_edit.php'),
    'minutes'  => new moodle_url('/local/rtocompliance/governance_minutes_edit.php'),
];
echo html_writer::link(
    $addBtnUrls[$tab] ?? $addBtnUrls['persons'],
    $addBtnLabels[$tab] ?? 'Add Record',
    ['class' => 'btn btn-primary']
);
echo html_writer::end_div();

echo html_writer::start_div('info-card');
echo html_writer::tag('h4', 'ASQA Leadership & Accountability Requirements (Standards 4.1 and 4.2)');
echo html_writer::tag('p',
    '<strong>Standard 4.1:</strong> Governing persons must be fit and proper, exercise appropriate oversight, make informed decisions, and promote a culture of integrity and transparency. ' .
    'Evidence includes: fit and proper declarations, suitability assessments, and meeting minutes showing active governance.<br>' .
    '<strong>Standard 4.2:</strong> Staff must understand their regulatory obligations, be kept informed of regulatory changes, and have documented roles and responsibilities. ' .
    'Evidence includes: job descriptions, delegation registers, and records of regulatory updates communicated to staff.'
);
echo html_writer::end_div();

echo html_writer::start_div('tab-nav');
echo html_writer::link(
    new moodle_url('/local/rtocompliance/governance.php', ['tab' => 'persons']),
    'Governing Persons',
    ['class' => $tab == 'persons' ? 'active' : '']
);
echo html_writer::link(
    new moodle_url('/local/rtocompliance/governance.php', ['tab' => 'roles']),
    'Roles & Responsibilities',
    ['class' => $tab == 'roles' ? 'active' : '']
);
echo html_writer::link(
    new moodle_url('/local/rtocompliance/governance.php', ['tab' => 'minutes']),
    'Meeting Minutes',
    ['class' => $tab == 'minutes' ? 'active' : '']
);
echo html_writer::link(
    new moodle_url('/local/rtocompliance/governance.php', ['tab' => 'changes']),
    'Material Changes',
    ['class' => $tab == 'changes' ? 'active' : '']
);
echo html_writer::link(
    new moodle_url('/local/rtocompliance/governance.php', ['tab' => 'adc']),
    'Annual Declaration',
    ['class' => $tab == 'adc' ? 'active' : '']
);
echo html_writer::end_div();

if ($tab == 'persons') {
    $persons = [];
    if ($DB->get_manager()->table_exists('local_rtocompliance_govpersons')) {
        $persons = $DB->get_records('local_rtocompliance_govpersons', null, 'fullname ASC');
    }

    if ($persons) {
        echo html_writer::start_tag('table', ['class' => 'data-table']);
        echo html_writer::start_tag('thead');
        echo html_writer::start_tag('tr');
        echo html_writer::tag('th', 'Name');
        echo html_writer::tag('th', 'Position');
        echo html_writer::tag('th', 'Fit & Proper');
        echo html_writer::tag('th', 'Suitability Assessment');
        echo html_writer::tag('th', 'Appointment Date');
        echo html_writer::tag('th', 'Actions');
        echo html_writer::end_tag('tr');
        echo html_writer::end_tag('thead');
        echo html_writer::start_tag('tbody');

        foreach ($persons as $person) {
            $fitproperclass = $person->fitproperdeclared ? 'badge-success' : 'badge-danger';
            $suitabilityclass = $person->suitabilityassessed ? 'badge-success' : 'badge-warning';

            echo html_writer::start_tag('tr');
            echo html_writer::tag('td', html_writer::tag('strong', format_string($person->fullname)));
            echo html_writer::tag('td', format_string($person->position ?: ucfirst(str_replace('_', ' ', $person->positiontype))));
            echo html_writer::tag('td', html_writer::tag('span', $person->fitproperdeclared ? 'Completed' : 'Required', ['class' => 'badge ' . $fitproperclass]));
            echo html_writer::tag('td', html_writer::tag('span', $person->suitabilityassessed ? 'Completed' : 'Pending', ['class' => 'badge ' . $suitabilityclass]));
            echo html_writer::tag('td', userdate($person->appointmentdate, '%d %b %Y'));
            echo html_writer::tag('td',
                html_writer::link(
                    new moodle_url('/local/rtocompliance/governance_edit.php', ['id' => $person->id]),
                    'Edit',
                    ['class' => 'btn btn-sm btn-secondary']
                )
            );
            echo html_writer::end_tag('tr');
        }

        echo html_writer::end_tag('tbody');
        echo html_writer::end_tag('table');
    } else {
        echo html_writer::start_div('empty-state');
        echo $OUTPUT->pix_icon('i/user', '', 'moodle', ['class' => 'empty-state-icon']);
        echo html_writer::tag('h3', 'No Governing Persons Recorded');
        echo html_writer::tag('p', 'Add directors, CEOs, and other high managerial agents with their fit and proper person declarations.');
        echo html_writer::link(
            new moodle_url('/local/rtocompliance/governance_edit.php'),
            'Add Governing Person',
            ['class' => 'btn btn-primary']
        );
        echo html_writer::end_div();
    }
} elseif ($tab == 'changes') {
    $changes = [];
    if ($DB->get_manager()->table_exists('local_rtocompliance_materialchanges')) {
        $changes = $DB->get_records('local_rtocompliance_materialchanges', null, 'effectivedate DESC', '*', 0, 50);
    }

    echo html_writer::start_div('info-card warning');
    echo html_writer::tag('h4', '10 Business Day Requirement');
    echo html_writer::tag('p', 'Material changes must be notified to ASQA within 10 business days. Track change identification, notification submission, and ASQA acknowledgement.');
    echo html_writer::end_div();

    if ($changes) {
        echo html_writer::start_tag('table', ['class' => 'data-table']);
        echo html_writer::start_tag('thead');
        echo html_writer::start_tag('tr');
        echo html_writer::tag('th', 'Change Type');
        echo html_writer::tag('th', 'Effective Date');
        echo html_writer::tag('th', 'Notification Deadline');
        echo html_writer::tag('th', 'Notified');
        echo html_writer::tag('th', 'Status');
        echo html_writer::tag('th', 'Actions');
        echo html_writer::end_tag('tr');
        echo html_writer::end_tag('thead');
        echo html_writer::start_tag('tbody');

        foreach ($changes as $change) {
            $statusclass = 'badge-info';
            if ($change->status == 'completed') $statusclass = 'badge-success';
            if ($change->status == 'overdue') $statusclass = 'badge-danger';
            if ($change->status == 'pending') $statusclass = 'badge-warning';

            echo html_writer::start_tag('tr');
            echo html_writer::tag('td', format_string($change->changetype));
            echo html_writer::tag('td', userdate($change->effectivedate, '%d %b %Y'));
            echo html_writer::tag('td', userdate($change->notificationdeadline, '%d %b %Y'));
            echo html_writer::tag('td', $change->asqanotificationdate ? userdate($change->asqanotificationdate, '%d %b %Y') : '-');
            echo html_writer::tag('td', html_writer::tag('span', ucfirst($change->status), ['class' => 'badge ' . $statusclass]));
            echo html_writer::tag('td',
                html_writer::link(
                    new moodle_url('/local/rtocompliance/governance_edit.php', ['id' => $change->id, 'type' => 'changes']),
                    'Edit',
                    ['class' => 'btn btn-sm btn-secondary']
                )
            );
            echo html_writer::end_tag('tr');
        }

        echo html_writer::end_tag('tbody');
        echo html_writer::end_tag('table');
    } else {
        echo html_writer::start_div('empty-state');
        echo $OUTPUT->pix_icon('i/switchrole', '', 'moodle', ['class' => 'empty-state-icon']);
        echo html_writer::tag('h3', 'No Material Changes');
        echo html_writer::tag('p', 'Changes to ownership, location, scope, or key personnel will be tracked here.');
        echo html_writer::link(
            new moodle_url('/local/rtocompliance/governance_edit.php', ['type' => 'changes']),
            'Record Material Change',
            ['class' => 'btn btn-primary']
        );
        echo html_writer::end_div();
    }
} elseif ($tab == 'roles') {
    $roles = [];
    if ($DB->get_manager()->table_exists('local_rtocompliance_roles')) {
        $roles = $DB->get_records('local_rtocompliance_roles', null, 'rolename ASC');
    }

    echo html_writer::start_div('info-card warning');
    echo html_writer::tag('h4', 'Standard 4.2 — Roles & Responsibilities Register');
    echo html_writer::tag('p', 'Document all key roles and how each role holder is kept informed of regulatory obligations and changes. This is core ASQA evidence for Standard 4.2 audits.');
    echo html_writer::end_div();

    if ($roles) {
        echo html_writer::start_tag('table', ['class' => 'data-table']);
        echo html_writer::start_tag('thead');
        echo html_writer::start_tag('tr');
        echo html_writer::tag('th', 'Role');
        echo html_writer::tag('th', 'Current Holder');
        echo html_writer::tag('th', 'Department');
        echo html_writer::tag('th', 'Reports To');
        echo html_writer::tag('th', 'Review Date');
        echo html_writer::tag('th', 'Actions');
        echo html_writer::end_tag('tr');
        echo html_writer::end_tag('thead');
        echo html_writer::start_tag('tbody');

        foreach ($roles as $role) {
            $overdue = $role->reviewdate && $role->reviewdate < time();
            echo html_writer::start_tag('tr');
            echo html_writer::tag('td', html_writer::tag('strong', format_string($role->rolename)));
            echo html_writer::tag('td', $role->roleowner ? format_string($role->roleowner) : html_writer::tag('span', 'Vacant', ['class' => 'text-muted']));
            echo html_writer::tag('td', $role->department ? format_string($role->department) : '-');
            echo html_writer::tag('td', $role->reportsto ? format_string($role->reportsto) : '-');
            echo html_writer::tag('td',
                $role->reviewdate
                    ? ($overdue
                        ? html_writer::tag('span', userdate($role->reviewdate, '%d %b %Y') . ' OVERDUE', ['class' => 'badge badge-danger'])
                        : userdate($role->reviewdate, '%d %b %Y'))
                    : '-'
            );
            echo html_writer::tag('td',
                html_writer::link(
                    new moodle_url('/local/rtocompliance/governance_roles_edit.php', ['id' => $role->id]),
                    'Edit',
                    ['class' => 'btn btn-sm btn-secondary']
                )
            );
            echo html_writer::end_tag('tr');
        }

        echo html_writer::end_tag('tbody');
        echo html_writer::end_tag('table');
    } else {
        echo html_writer::start_div('empty-state');
        echo $OUTPUT->pix_icon('i/user', '', 'moodle', ['class' => 'empty-state-icon']);
        echo html_writer::tag('h3', 'No Roles Recorded');
        echo html_writer::tag('p', 'Document all key roles including: RTO Manager, CEO, Training Manager, Assessors, Administrative staff, and any third-party arrangement contacts. For each role show how the holder is kept informed of their regulatory obligations and changes to ASQA standards.');
        echo html_writer::link(
            new moodle_url('/local/rtocompliance/governance_roles_edit.php'),
            'Add First Role',
            ['class' => 'btn btn-primary']
        );
        echo html_writer::end_div();
    }

} elseif ($tab == 'minutes') {
    $minutes = [];
    if ($DB->get_manager()->table_exists('local_rtocompliance_minutes')) {
        $minutes = $DB->get_records('local_rtocompliance_minutes', null, 'meetingdate DESC', '*', 0, 50);
    }

    echo html_writer::start_div('info-card warning');
    echo html_writer::tag('h4', 'Standards 4.1 & 4.2 — Meeting Minutes as Evidence');
    echo html_writer::tag('p', 'Meeting minutes are primary ASQA evidence that governing persons are exercising active oversight and making informed decisions. Minutes must show: compliance items discussed, financial oversight, risk review, and regulatory updates communicated to staff.');
    echo html_writer::end_div();

    if ($minutes) {
        echo html_writer::start_tag('table', ['class' => 'data-table']);
        echo html_writer::start_tag('thead');
        echo html_writer::start_tag('tr');
        echo html_writer::tag('th', 'Meeting');
        echo html_writer::tag('th', 'Type');
        echo html_writer::tag('th', 'Date');
        echo html_writer::tag('th', 'Location');
        echo html_writer::tag('th', 'Attendees');
        echo html_writer::tag('th', 'Compliance Items');
        echo html_writer::tag('th', 'Actions');
        echo html_writer::end_tag('tr');
        echo html_writer::end_tag('thead');
        echo html_writer::start_tag('tbody');

        $typeLabels = ['board' => 'Board', 'management' => 'Management', 'quality' => 'Quality', 'staff' => 'Staff', 'other' => 'Other'];
        $typeColors = ['board' => 'badge-purple', 'management' => 'badge-blue', 'quality' => 'badge-green', 'staff' => 'badge-info', 'other' => 'badge-secondary'];

        foreach ($minutes as $min) {
            $typeLabel = $typeLabels[$min->meetingtype] ?? ucfirst($min->meetingtype);
            $typeColor = $typeColors[$min->meetingtype] ?? 'badge-secondary';

            echo html_writer::start_tag('tr');
            echo html_writer::tag('td', html_writer::tag('strong', format_string($min->meetingtitle)));
            echo html_writer::tag('td', html_writer::tag('span', $typeLabel, ['class' => 'badge ' . $typeColor]));
            echo html_writer::tag('td', userdate($min->meetingdate, '%d %b %Y'));
            echo html_writer::tag('td', $min->location ? format_string($min->location) : '-');
            echo html_writer::tag('td', $min->attendees ? html_writer::tag('small', format_string(substr($min->attendees, 0, 60))) : '-');
            echo html_writer::tag('td', $min->complianceitems
                ? html_writer::tag('span', 'Yes', ['class' => 'badge badge-success'])
                : html_writer::tag('span', 'Not recorded', ['class' => 'badge badge-warning']));
            echo html_writer::tag('td',
                html_writer::link(
                    new moodle_url('/local/rtocompliance/governance_minutes_edit.php', ['id' => $min->id]),
                    'View / Edit',
                    ['class' => 'btn btn-sm btn-secondary']
                )
            );
            echo html_writer::end_tag('tr');
        }

        echo html_writer::end_tag('tbody');
        echo html_writer::end_tag('table');
    } else {
        echo html_writer::start_div('empty-state');
        echo $OUTPUT->pix_icon('i/docs', '', 'moodle', ['class' => 'empty-state-icon']);
        echo html_writer::tag('h3', 'No Meeting Minutes Recorded');
        echo html_writer::tag('p', 'Record minutes for board, management, and quality meetings. ASQA expects to see evidence of active governance including: financial review, risk review, regulatory updates, and decisions made. Minutes with compliance agenda items are the strongest possible evidence for Standards 4.1 and 4.2.');
        echo html_writer::link(
            new moodle_url('/local/rtocompliance/governance_minutes_edit.php'),
            'Add First Meeting Minutes',
            ['class' => 'btn btn-primary']
        );
        echo html_writer::end_div();
    }

} else {
    $declarations = [];
    if ($DB->get_manager()->table_exists('local_rtocompliance_adc')) {
        $declarations = $DB->get_records('local_rtocompliance_adc', null, 'year DESC', '*', 0, 10);
    }

    if ($declarations) {
        echo html_writer::start_tag('table', ['class' => 'data-table']);
        echo html_writer::start_tag('thead');
        echo html_writer::start_tag('tr');
        echo html_writer::tag('th', 'Year');
        echo html_writer::tag('th', 'Submitted By');
        echo html_writer::tag('th', 'Date Submitted');
        echo html_writer::tag('th', 'Evidence Attached');
        echo html_writer::tag('th', 'Status');
        echo html_writer::tag('th', 'Actions');
        echo html_writer::end_tag('tr');
        echo html_writer::end_tag('thead');
        echo html_writer::start_tag('tbody');

        foreach ($declarations as $decl) {
            $statusclass = 'badge-success';
            if ($decl->status == 'draft') $statusclass = 'badge-warning';

            echo html_writer::start_tag('tr');
            echo html_writer::tag('td', html_writer::tag('strong', $decl->year));
            echo html_writer::tag('td', format_string($decl->submittedby));
            echo html_writer::tag('td', $decl->datesubmitted ? userdate($decl->datesubmitted, '%d %b %Y') : '-');
            echo html_writer::tag('td', $decl->evidencecount . ' documents');
            echo html_writer::tag('td', html_writer::tag('span', ucfirst($decl->status), ['class' => 'badge ' . $statusclass]));
            echo html_writer::tag('td',
                html_writer::link(
                    new moodle_url('/local/rtocompliance/governance_edit.php', ['id' => $decl->id, 'type' => 'adc']),
                    'View',
                    ['class' => 'btn btn-sm btn-secondary']
                )
            );
            echo html_writer::end_tag('tr');
        }

        echo html_writer::end_tag('tbody');
        echo html_writer::end_tag('table');
    } else {
        echo html_writer::start_div('empty-state');
        echo $OUTPUT->pix_icon('i/completion-auto-pass', '', 'moodle', ['class' => 'empty-state-icon']);
        echo html_writer::tag('h3', 'No Declarations Recorded');
        echo html_writer::tag('p', 'Track your Annual Declaration of Compliance submissions with supporting evidence.');
        echo html_writer::link(
            new moodle_url('/local/rtocompliance/governance_edit.php', ['type' => 'adc']),
            'Start ADC Submission',
            ['class' => 'btn btn-primary']
        );
        echo html_writer::end_div();
    }
}

echo html_writer::end_div();

echo $OUTPUT->footer();
