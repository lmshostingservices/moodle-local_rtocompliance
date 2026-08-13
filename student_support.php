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

admin_externalpage_setup('local_rtocompliance_student_support');
$PAGE->set_url('/local/rtocompliance/student_support.php');
$PAGE->set_title('Student Support');
$PAGE->set_heading('Student Support');

$PAGE->requires->css('/local/rtocompliance/styles.css');
$PAGE->add_body_class("path-local-rtocompliance");

echo $OUTPUT->header();
echo local_rtocompliance_render_nav_header('Student Support', null, null, 'student_support');

echo html_writer::start_div('compliance-container');

echo html_writer::start_div('compliance-header');
echo html_writer::tag('h2', 'Student Support System (Standards 2.3 – 2.6)');
echo html_writer::end_div();

// Intro card.
echo html_writer::start_div('info-card');
echo html_writer::tag('h4', 'Standards 2.3 to 2.6 — Training Support, Reasonable Adjustments, Diversity & Wellbeing');
echo html_writer::tag('p', '
    This page is the organisation-level Student Support system. Selections made here form part of the
    RTO\'s standing evidence — the underlying lists are visible to prospective and current students and
    are the source of the support, adjustments, policy and wellbeing options offered to learners.
    Per-student support records are entered through the <strong>Trainer Input</strong> page and saved
    against individual student profiles.
');
echo html_writer::end_div();

// Compliance dashboard at top.
echo html_writer::start_div('info-card', ['style' => 'margin-top:1.5rem;background:#f0fdf4;border:1px solid #bbf7d0;']);
echo html_writer::tag('h4', 'Live Compliance Dashboard', ['style' => 'margin:0 0 0.75rem;color:#166534;']);
echo html_writer::div('Loading…', '', ['id' => 'rtoSupportDashboard', 'style' => 'font-size:0.95rem;line-height:1.7;']);
echo html_writer::end_div();

// Cards grid.
echo html_writer::start_div('', ['style' => 'display:grid;grid-template-columns:repeat(auto-fit,minmax(360px,1fr));gap:1.25rem;margin-top:1.5rem;']);

// Standard 2.3 — Training Support Services.
echo html_writer::start_div('info-card', ['style' => 'margin:0;']);
echo html_writer::tag('h4', 'Training Support Services (Standard 2.3)');
echo html_writer::tag('p', 'Tick the support services this RTO offers. Selections persist locally and feed the dashboard.', ['style' => 'font-size:0.88rem;color:#6b7280;margin:0 0 0.75rem;']);
echo html_writer::div('', '', ['id' => 'rtoSupportServices']);
echo html_writer::end_div();

// Standard 2.4 — Reasonable Adjustments.
echo html_writer::start_div('info-card', ['style' => 'margin:0;']);
echo html_writer::tag('h4', 'Reasonable Adjustments (Standard 2.4)');
echo html_writer::tag('p', 'Select the standing list of reasonable adjustments available. Per-student application is recorded via Trainer Input.', ['style' => 'font-size:0.88rem;color:#6b7280;margin:0 0 0.75rem;']);
echo html_writer::div('', '', ['id' => 'rtoAdjustmentsList']);
echo html_writer::end_div();

// Standard 2.5 policies are managed via the Diversity and Inclusion Policies card
// on the Marketing Information (Standard 2.1) page which links to the RTO website.

// Standard 2.6 — Wellbeing Support.
echo html_writer::start_div('info-card', ['style' => 'margin:0;']);
echo html_writer::tag('h4', 'Wellbeing Support (Standard 2.6)');
echo html_writer::tag('p', 'Tick the wellbeing supports the RTO provides. Per-student wellbeing records are entered via Trainer Input.', ['style' => 'font-size:0.88rem;color:#6b7280;margin:0 0 0.75rem;']);
echo html_writer::div('', '', ['id' => 'rtoWellbeingSupport']);
echo html_writer::end_div();

echo html_writer::end_div(); // grid

// Related pages.
echo html_writer::start_div('', ['style' => 'margin-top:2rem;padding:1.5rem;background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;']);
echo html_writer::tag('h4', 'Related pages', ['style' => 'margin:0 0 0.75rem;color:#0369a1;']);
echo '<div style="display:flex;flex-wrap:wrap;gap:0.75rem;">';
echo '<a href="' . (new moodle_url('/local/rtocompliance/students.php'))->out() . '" class="btn btn-outline-primary btn-sm">Student Records</a>';
echo '<a href="' . (new moodle_url('/local/rtocompliance/student_support_input.php'))->out() . '" class="btn btn-outline-primary btn-sm">Trainer Input</a>';
echo '</div>';
echo html_writer::end_div();

echo html_writer::end_div(); // compliance-container

// =========================================================
// Inline JS — Standard 2.3-2.6 functional system.
// All state persists via localStorage under key 'rto_support_v1'.
// Scoped via element IDs prefixed rto* and only attaches inside this page.
// =========================================================
?>
<script>
(function () {
    'use strict';

    // --- Data sources (organisation-level standing lists) ---
    var SUPPORT_SERVICES = [
        'One-on-one trainer support',
        'Scheduled tutorials and workshops',
        'Additional coaching',
        'Mentoring',
        'LMS guidance',
        'Software support',
        'LLN assessment prior to enrolment',
        'Individual LLN support',
        'Referral to LLN specialists'
    ];
    var ADJUSTMENTS = [
        'Extended time',
        'Alternative assessment',
        'Assistive technology',
        'Modified materials',
        'Flexible learning'
    ];
    var WELLBEING = [
        'Wellbeing plans',
        'Flexible study arrangements',
        'Counselling referrals',
        'Student handbook support info',
        'LMS support resources',
        'Induction support info',
        'External support services'
    ];

    var STORAGE_KEY = 'rto_support_v1';

    function loadState() {
        try { return JSON.parse(localStorage.getItem(STORAGE_KEY)) || {}; }
        catch (e) { return {}; }
    }
    function saveState(state) {
        try { localStorage.setItem(STORAGE_KEY, JSON.stringify(state)); } catch (e) {}
    }
    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c];
        });
    }

    function renderChecklist(containerId, items, stateKey) {
        var el = document.getElementById(containerId);
        if (!el) return;
        var state = loadState();
        var selected = state[stateKey] || {};
        el.innerHTML = items.map(function (item, idx) {
            var id = containerId + '_' + idx;
            var checked = selected[item] ? ' checked' : '';
            return '<label for="' + id + '" style="display:flex;align-items:flex-start;gap:0.5rem;padding:0.35rem 0;cursor:pointer;line-height:1.5;">' +
                '<input type="checkbox" id="' + id + '" data-item="' + escapeHtml(item) + '"' + checked + ' style="margin-top:0.25rem;flex-shrink:0;"> ' +
                '<span>' + escapeHtml(item) + '</span></label>';
        }).join('');
        el.addEventListener('change', function (ev) {
            if (ev.target.tagName !== 'INPUT') return;
            var s = loadState();
            s[stateKey] = s[stateKey] || {};
            var item = ev.target.getAttribute('data-item');
            if (ev.target.checked) { s[stateKey][item] = true; }
            else { delete s[stateKey][item]; }
            saveState(s);
            renderDashboard();
        });
    }

    function renderDashboard() {
        var el = document.getElementById('rtoSupportDashboard');
        if (!el) return;
        var state = loadState();
        function count(key) { return Object.keys(state[key] || {}).length; }
        var ss = count('supportServices');
        var adj = count('adjustments');
        var wb = count('wellbeing');
        var totalSelectable = SUPPORT_SERVICES.length + ADJUSTMENTS.length + WELLBEING.length;
        var totalSelected = ss + adj + wb;
        var pct = totalSelectable === 0 ? 0 : Math.round((totalSelected / totalSelectable) * 100);
        var status = pct >= 70 ? '<span style="color:#166534;font-weight:600;">&#10003; Strong organisation-level cover</span>'
                   : pct >= 40 ? '<span style="color:#a16207;font-weight:600;">&#9888; Partial cover — review gaps</span>'
                              : '<span style="color:#b91c1c;font-weight:600;">&#9888; Insufficient — select more services</span>';
        el.innerHTML =
            '<div>Support services selected (Std 2.3): <strong>' + ss + ' / ' + SUPPORT_SERVICES.length + '</strong></div>' +
            '<div>Reasonable adjustments listed (Std 2.4): <strong>' + adj + ' / ' + ADJUSTMENTS.length + '</strong></div>' +
            '<div>Wellbeing supports listed (Std 2.6): <strong>' + wb + ' / ' + WELLBEING.length + '</strong></div>' +
            '<div style="margin-top:0.5rem;">Overall organisation-level cover: <strong>' + pct + '%</strong> &nbsp; ' + status + '</div>';
    }

    function init() {
        renderChecklist('rtoSupportServices', SUPPORT_SERVICES, 'supportServices');
        renderChecklist('rtoAdjustmentsList', ADJUSTMENTS, 'adjustments');
        renderChecklist('rtoWellbeingSupport', WELLBEING, 'wellbeing');
        renderDashboard();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else { init(); }
})();
</script>
<?php
echo $OUTPUT->footer();
