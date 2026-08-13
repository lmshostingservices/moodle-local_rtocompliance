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
require_once(__DIR__ . '/lib.php');

admin_externalpage_setup('local_rtocompliance_marketing_info');
$context = context_system::instance();
require_capability('moodle/site:config', $context);
$PAGE->set_url('/local/rtocompliance/marketing_info.php');
$PAGE->set_title('Marketing Information');
$PAGE->set_heading('Marketing Information');

$PAGE->requires->css('/local/rtocompliance/styles.css');
$PAGE->add_body_class("path-local-rtocompliance");

echo $OUTPUT->header();
echo local_rtocompliance_render_nav_header('Marketing Information', null, null, 'marketing_info');

echo html_writer::start_div('compliance-container');

echo html_writer::start_div('compliance-header');
echo html_writer::tag('h2', 'Marketing Information');
echo html_writer::end_div();

echo html_writer::start_div('info-card');
echo html_writer::tag('h4', 'Standard 2.1 – Information about the organisation and training products');
echo html_writer::tag('p', '
    <strong>Clause 2.1:</strong> The RTO must provide accurate and accessible information to prospective and current students about its training products and services,
    including any third party arrangements, entry requirements, fees, refund arrangements, and complaints processes.
');
echo html_writer::end_div();

echo html_writer::start_div('info-card', ['style' => 'margin-top:1.5rem;']);
echo html_writer::tag('h4', 'What to document here');
echo '<ul style="margin:0.5rem 0 0 1.2rem;line-height:1.8;">';
echo '<li>Pre-enrolment information checklist and evidence of delivery</li>';
echo '<li>Marketing materials review schedule and approval records</li>';
echo '<li>Fees, refund policy, and fee protection arrangements</li>';
echo '<li>Student handbook and course guide version control</li>';
echo '<li>Complaints and appeals information provided to students</li>';
echo '<li>Enrolment terms and conditions (signed acknowledgements)</li>';
echo '</ul>';
echo html_writer::end_div();

echo html_writer::start_div('', ['style' => 'margin-top:2rem;padding:1.5rem;background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;']);
echo html_writer::tag('h4', 'Related pages', ['style' => 'margin:0 0 0.75rem;color:#0369a1;']);
echo '<div style="display:flex;flex-wrap:wrap;gap:0.75rem;">';
echo '<a href="' . (new moodle_url('/local/rtocompliance/marketing_cards.php'))->out() . '" class="btn btn-primary btn-sm">Standard 2.1 Information Cards</a>';
echo '<a href="' . (new moodle_url('/local/rtocompliance/feeprotection.php'))->out() . '" class="btn btn-outline-primary btn-sm">Fee Protection</a>';
echo '<a href="' . (new moodle_url('/local/rtocompliance/student_support.php'))->out() . '" class="btn btn-outline-primary btn-sm">Student Support</a>';
echo '<a href="' . (new moodle_url('/local/rtocompliance/students.php'))->out() . '" class="btn btn-outline-primary btn-sm">Student Records</a>';
echo '</div>';
echo html_writer::end_div();

echo html_writer::end_div();

echo $OUTPUT->footer();
