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
 * RTO Compliance plugin — locations.php.
 *
 * @package    local_rtocompliance
 * @copyright  2025 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/lib.php');
require_once(__DIR__ . '/classes/audit_logger.php');

use local_rtocompliance\audit_logger;

admin_externalpage_setup('local_rtocompliance_locations');
// ACCESS (v6.3.8): admin_externalpage_setup() above already enforces the capability this
// page is registered with in settings.php. Stating it explicitly keeps the requirement
// visible in this file instead of only in the settings registration — same check, no cost.
require_capability('local/rtocompliance:manage', context_system::instance());
require_login();

$action = optional_param('action', '', PARAM_ALPHA);
$id     = optional_param('id', 0, PARAM_INT);

$PAGE->requires->css('/local/rtocompliance/styles.css');

if ($action === 'delete' && $id && confirm_sesskey()) {
    $loc = $DB->get_record('local_rtocompliance_locations', ['id' => $id], '*', MUST_EXIST);
    $DB->delete_records('local_rtocompliance_locations', ['id' => $id]);
    // v5.9.368 AUDIT-FIX: a deletion was being logged as a CREATE with empty data.
    // Use log_delete and capture the deleted record so the audit trail is correct.
    audit_logger::log_delete('location', $id, 'Delivery location deleted: ' . $loc->locationname, (array) $loc);
    redirect(
        new moodle_url('/local/rtocompliance/locations.php'),
        get_string('location_deleted', 'local_rtocompliance'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

$PAGE->add_body_class("path-local-rtocompliance");
echo $OUTPUT->header();
echo local_rtocompliance_render_nav_header(
    get_string('delivery_locations', 'local_rtocompliance'),
    null, null, 'locations'
);

echo html_writer::start_div('compliance-container');

echo html_writer::start_div('compliance-header');
echo html_writer::tag('h2', get_string('delivery_locations', 'local_rtocompliance'));
echo html_writer::link(
    new moodle_url('/local/rtocompliance/location_edit.php'),
    get_string('add_location', 'local_rtocompliance'),
    ['class' => 'btn btn-primary', 'title' => 'Add a new delivery location']
);
echo html_writer::end_div();

echo html_writer::start_div('info-card');
echo html_writer::tag('p', get_string('locations_intro', 'local_rtocompliance'));
echo html_writer::end_div();

$locations = $DB->get_records('local_rtocompliance_locations', [], 'locationname ASC');

if ($locations) {
    $table = new html_table();
    $mkhead = function ($text, $title) {
        $cell = new html_table_cell($text);
        $cell->header = true;
        $cell->attributes['title'] = $title;
        return $cell;
    };
    $table->head = [
        $mkhead(get_string('location_id', 'local_rtocompliance'), 'Your internal identifier for this delivery location'),
        $mkhead(get_string('location_name', 'local_rtocompliance'), 'Name of the delivery location'),
        $mkhead(get_string('suburb', 'local_rtocompliance'), 'Suburb where the location is situated'),
        $mkhead(get_string('postcode', 'local_rtocompliance'), 'Postcode of the location'),
        $mkhead(get_string('state', 'local_rtocompliance'), 'State or territory of the location'),
        $mkhead(get_string('status'), 'Whether this location is currently active or inactive'),
        $mkhead(get_string('rule9b_col', 'local_rtocompliance'), 'ASQA Rule 9B building classification status for this location'),
        $mkhead(get_string('actions'), 'Actions available for this location'),
    ];
    $table->attributes['class'] = 'data-table';

    $statecodes = [
        '01' => 'NSW', '02' => 'VIC', '03' => 'QLD', '04' => 'SA',
        '05' => 'WA', '06' => 'TAS', '07' => 'NT', '08' => 'ACT',
        '99' => 'Other/OS',
    ];

    foreach ($locations as $loc) {
        $statename = $statecodes[$loc->statecode] ?? $loc->statecode;
        $statusbadge = $loc->status === 'active'
            ? html_writer::tag('span', get_string('active', 'local_rtocompliance'), ['class' => 'badge badge-success', 'style' => 'background:#28a745;color:#fff;'])
            : html_writer::tag('span', get_string('inactive', 'local_rtocompliance'), ['class' => 'badge badge-secondary', 'style' => 'background:#6c757d;color:#fff;']);

        $actions =
            html_writer::link(
                new moodle_url('/local/rtocompliance/location_edit.php', ['id' => $loc->id]),
                get_string('edit'),
                ['class' => 'btn btn-sm btn-outline-primary', 'style' => 'margin-right:4px;', 'title' => 'Edit this delivery location']
            ) .
            html_writer::link(
                new moodle_url('/local/rtocompliance/locations.php', ['action' => 'delete', 'id' => $loc->id, 'sesskey' => sesskey()]),
                get_string('delete'),
                ['class' => 'btn btn-sm btn-outline-danger', 'title' => 'Delete this delivery location', 'onclick' => "return confirm('" . get_string('confirm_delete_location', 'local_rtocompliance') . "');"]
            );

        // 9B certificate download link (if uploaded)
        $fs = get_file_storage();
        $syscontext = context_system::instance();
        $certfiles = $fs->get_area_files($syscontext->id, 'local_rtocompliance', 'certificate9b', $loc->id, 'id', false);
        $certlinks = '';
        if (!empty($certfiles)) {
            $certlinksArr = [];
            foreach ($certfiles as $certfile) {
                $certurl = moodle_url::make_pluginfile_url($syscontext->id, 'local_rtocompliance',
                    'certificate9b', $loc->id, '/', $certfile->get_filename(), true);
                $certlinksArr[] = html_writer::link($certurl,
                    '<svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-1px;margin-right:2px;"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>'
                    . htmlspecialchars($certfile->get_filename(), ENT_QUOTES, 'UTF-8'),
                    ['target' => '_blank', 'rel' => 'noopener', 'class' => 'rtoc-cert-link',
                     'style' => 'font-size:11px;white-space:nowrap;display:block;']
                );
            }
            $certlinks = implode('', $certlinksArr);
        }

        // Rule 9B building classification badge
        $rule9bVal = isset($loc->rule9b_approved) ? (int)$loc->rule9b_approved : 0;
        if ($rule9bVal) {
            $checkIcon = '<svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" '
                . 'fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" '
                . 'style="vertical-align:-1px;margin-right:3px;">'
                . '<polyline points="20 6 9 17 4 12"/></svg>';
            $rule9bBadge = '<span class="rtoc-badge rtoc-badge--9b-yes" '
                . 'title="This location holds a Class 9B building classification (or equivalent) for VET delivery, as required by ASQA Standards.">'
                . $checkIcon . get_string('rule9b_badge_yes', 'local_rtocompliance')
                . '</span>';
        } else {
            $crossIcon = '<svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" '
                . 'fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" '
                . 'style="vertical-align:-1px;margin-right:3px;">'
                . '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';
            $rule9bBadge = '<span class="rtoc-badge rtoc-badge--9b-no" '
                . 'title="This location has not been marked as holding a Class 9B building classification. Update the location to record its ASQA Rule 9B status.">'
                . $crossIcon . get_string('rule9b_badge_no', 'local_rtocompliance')
                . '</span>';
        }

        $table->data[] = [
            $loc->locationid,
            $loc->locationname,
            $loc->suburb ?: '-',
            $loc->postcode ?: '-',
            $statename ?: '-',
            $statusbadge,
            $rule9bBadge . ($certlinks ? '<div style="margin-top:4px;">' . $certlinks . '</div>' : ''),
            $actions,
        ];
    }

    echo html_writer::table($table);
} else {
    echo html_writer::div(
        html_writer::tag('p', get_string('no_locations', 'local_rtocompliance')) .
        html_writer::link(
            new moodle_url('/local/rtocompliance/location_edit.php'),
            get_string('add_first_location', 'local_rtocompliance'),
            ['class' => 'btn btn-primary', 'title' => 'Add your first delivery location']
        ),
        'alert alert-info'
    );
}

echo html_writer::start_div('', ['style' => 'margin-top:2rem;padding:1.5rem;background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;']);
echo html_writer::tag('h4', 'Related pages', ['style' => 'margin:0 0 0.75rem;color:#0369a1;']);
echo html_writer::tag('p', 'Delivery locations and physical resources feed directly into TAS Section 7 — Learning Resources &amp; Equipment. Document what facilities are available at each location and why they are fit-for-purpose for the training products delivered there.', ['style' => 'margin:0 0 0.75rem;font-size:0.9rem;color:#374151;']);
echo '<div style="display:flex;flex-wrap:wrap;gap:0.75rem;">';
echo '<a href="' . (new moodle_url('/local/rtocompliance/tas_edit.php'))->out() . '#tas-section-7" class="btn btn-outline-primary btn-sm" title="Open TAS Section 7 — Learning Resources and Equipment">TAS Section 7 — Learning Resources &amp; Equipment</a>'; // v5.9.368: drop bogus ?%23= param, keep real #fragment
echo '<a href="' . (new moodle_url('/local/rtocompliance/tas.php'))->out() . '" class="btn btn-outline-primary btn-sm" title="Open the TAS Generator">TAS Generator</a>';
echo '<a href="' . (new moodle_url('/local/rtocompliance/practice_guides.php', ['guide' => 'facilities']))->out() . '" class="btn btn-outline-primary btn-sm" title="Open the ASQA practice guide on facilities">ASQA Practice Guide — Facilities</a>';
echo '</div>';
echo html_writer::end_div();

echo html_writer::end_div();

echo $OUTPUT->footer();
