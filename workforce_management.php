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
 * RTO Compliance plugin — workforce_management.php.
 *
 * @package    local_rtocompliance
 * @copyright  2025 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/lib.php');

admin_externalpage_setup('local_rtocompliance_workforce_management');
// ACCESS (v6.3.8): admin_externalpage_setup() above already enforces the capability this
// page is registered with in settings.php. Stating it explicitly keeps the requirement
// visible in this file instead of only in the settings registration — same check, no cost.
require_capability('local/rtocompliance:manage', context_system::instance());
require_login();
$PAGE->set_url('/local/rtocompliance/workforce_management.php');
$PAGE->set_title('VET Workforce Management');
$PAGE->set_heading('VET Workforce Management');

$PAGE->requires->css('/local/rtocompliance/styles.css');
$PAGE->add_body_class("path-local-rtocompliance");

echo $OUTPUT->header();
echo local_rtocompliance_render_nav_header('VET Workforce Management', null, null, 'workforce_management');

echo html_writer::start_div('compliance-container');

echo html_writer::start_div('compliance-header');
echo html_writer::tag('h2', 'VET Workforce Management');
echo html_writer::end_div();

echo html_writer::start_div('info-card');
echo html_writer::tag('h4', 'Standard 3.1 — VET Workforce Management');
echo html_writer::tag('p', '
    <strong>Standard 3.1:</strong> The RTO must ensure it has sufficient trainers and assessors to deliver quality training and assessment to its students.
    This requires workforce planning, capability assessment, and maintaining appropriate staffing ratios aligned to student load and scope of registration.<br><br>
    The RTO must be able to demonstrate it has the right number of suitably qualified and experienced trainers and assessors to deliver every training product on its scope of registration.
');
echo html_writer::end_div();

echo html_writer::start_div('info-card', ['style' => 'margin-top:1.5rem;']);
echo html_writer::tag('h4', 'What to document here');
echo '<ul style="margin:0.5rem 0 0 1.2rem;line-height:1.8;">';
echo '<li>Current workforce summary — trainers and assessors by qualification scope</li>';
echo '<li>Student-to-trainer ratios by program</li>';
echo '<li>Workforce planning records and succession arrangements</li>';
echo '<li>Casual and sessional staff onboarding and credential verification</li>';
echo '<li>Gaps in workforce capacity and mitigation strategies</li>';
echo '<li>Third-party delivery workforce arrangements</li>';
echo '</ul>';
echo html_writer::end_div();

echo html_writer::start_div('info-card', ['style' => 'margin-top:1.5rem;']);
echo html_writer::tag('h4', 'Comprehensive Workforce Check System');
echo html_writer::tag('p', 'Enter your current workforce data to generate a trainer-load analysis and a workforce-planning summary you can use as a starting point for your own evidence. <strong>The ratios below are indicative planning figures, not ASQA-mandated ratios</strong> — ASQA does not set a single mandated ratio and instead expects each RTO to demonstrate <strong>sufficient</strong> staffing for the load it carries. Adjust the benchmarks to whatever your own TAS and workforce plan justify. This tool does not verify trainer credentials or vocational competency; that verification is done in the Trainer &amp; Assessor Register.');
echo <<<'HTML'
<style>
.wfm-section { background:#f9fafb; border:1px solid #e5e7eb; border-radius:8px; padding:1.25rem; margin-top:1rem; }
.wfm-section h5 { margin:0 0 0.75rem; font-size:1rem; color:#374151; font-weight:700; }
.wfm-input-row { display:grid; grid-template-columns:1fr 1fr; gap:1rem; margin-bottom:0.75rem; }
.wfm-input-row label { display:block; font-size:0.88rem; font-weight:600; color:#374151; margin-bottom:3px; }
.wfm-input { width:100%; padding:7px 10px; border:1px solid #d1d5db; border-radius:6px; font-size:0.95rem; box-sizing:border-box; }
.wfm-result-card { border-radius:8px; padding:1.25rem; margin-top:1rem; }
.wfm-alert { padding:0.75rem 1rem; border-radius:6px; margin-top:0.5rem; font-size:0.9rem; }
.wfm-alert.danger { background:#fee2e2; color:#991b1b; border:1px solid #fca5a5; }
.wfm-alert.warn  { background:#fef9c3; color:#854d0e; border:1px solid #fde047; }
.wfm-alert.ok    { background:#d1fae5; color:#065f46; border:1px solid #6ee7b7; }
.wfm-mapping-table { width:100%; border-collapse:collapse; font-size:0.88rem; margin-top:0.75rem; }
.wfm-mapping-table th { background:#f3f4f6; padding:8px 10px; text-align:left; font-weight:700; border:1px solid #e5e7eb; }
.wfm-mapping-table td { padding:7px 10px; border:1px solid #e5e7eb; }
.wfm-mapping-table tr.gap-row td { background:#fef2f2; color:#991b1b; }
.wfm-mapping-table tr.ok-row  td { background:#f0fdf4; color:#166534; }
.wfm-compliance-text { background:#fffbeb; border:1px solid #fde68a; border-radius:8px; padding:1.25rem; margin-top:1rem; font-size:0.9rem; line-height:1.7; white-space:pre-wrap; }
</style>

<div class="wfm-section">
  <h5>Step 1 — Core Workforce Inputs</h5>
  <div class="wfm-input-row">
    <div>
      <label>Number of active trainers/assessors</label>
      <input type="number" id="wfm-trainers" class="wfm-input" min="1" value="2" oninput="wfmRun()">
    </div>
    <div>
      <label>Total enrolled students (current intake)</label>
      <input type="number" id="wfm-students" class="wfm-input" min="1" value="20" oninput="wfmRun()">
    </div>
  </div>
  <div class="wfm-input-row">
    <div>
      <label>Primary delivery mode</label>
      <select id="wfm-mode" class="wfm-input" onchange="wfmRun()">
        <option value="face-to-face">Face-to-Face (benchmark 1:20)</option>
        <option value="online">Online (benchmark 1:12 — higher support)</option>
        <option value="workplace">Workplace-Based (benchmark 1:15)</option>
        <option value="mixed">Mixed/Blended (benchmark 1:25)</option>
      </select>
    </div>
    <div>
      <label>FTE teaching hours per trainer per week</label>
      <input type="number" id="wfm-fte" class="wfm-input" min="1" max="40" value="20" oninput="wfmRun()">
      <small style="color:#6b7280;">Teaching only — exclude prep, admin, meetings</small>
    </div>
  </div>
</div>

<div class="wfm-section">
  <h5>Step 2 — Assessment Load Calculator</h5>
  <div class="wfm-input-row">
    <div>
      <label>Number of qualifications/skill sets delivered</label>
      <input type="number" id="wfm-quals" class="wfm-input" min="1" value="1" oninput="wfmRun()">
    </div>
    <div>
      <!-- BUG-WFD-FIELD FIX: renamed from "Number of hours in each assessment" to clarify
           this field is the count of assessments per qualification (not hours), matching
           the wfmRun() variable "assessPerQual". -->
      <label>Number of assessments per qualification</label>
      <input type="number" id="wfm-assess" class="wfm-input" min="1" value="5" oninput="wfmRun()">
    </div>
  </div>
  <div class="wfm-input-row">
    <div>
      <!-- BUG-WFD-FIELD FIX: "Primary assessment type" select converted to a direct number
           input so trainers can enter their actual average marking hours per assessment
           rather than selecting from preset category approximations. -->
      <label>Number of hours in each assessment</label>
      <input type="number" id="wfm-assess-type" class="wfm-input" min="0.5" max="20" step="0.5" value="2" oninput="wfmRun()">
    </div>
    <div>
      <label>Delivery weeks (course duration)</label>
      <input type="number" id="wfm-weeks" class="wfm-input" min="1" max="52" value="10" oninput="wfmRun()">
      <small style="color:#6b7280;">Total teaching weeks in the delivery period (used to calculate weekly marking load)</small>
    </div>
  </div>
  <div class="wfm-input-row">
    <div>
      <label>Trainer capacity (hrs/week — all tasks)</label>
      <input type="number" id="wfm-capacity" class="wfm-input" min="1" value="30" oninput="wfmRun()">
      <small style="color:#6b7280;">Delivery + marking + support + admin</small>
    </div>
    <div></div>
  </div>
</div>

<div class="wfm-section">
  <h5>Step 3 — Unit-to-Trainer Assignment Checker</h5>
  <p style="font-size:0.88rem;color:#6b7280;margin:0 0 0.75rem;">List the units being delivered and the assigned trainer. The system will flag any units with no trainer assigned. Enter one unit per row: <strong>UNIT CODE | Trainer Name</strong> (or leave Trainer blank to mark as a gap).</p>
  <textarea id="wfm-unit-map" class="wfm-input" rows="6" style="resize:vertical;font-family:monospace;font-size:0.88rem;" oninput="wfmRun()" placeholder="BSBCRT511 | Sarah Jones&#10;BSBOPS501 | Sarah Jones&#10;BSBSUS511 | Daniel Smith&#10;BSBPEF502 | (no trainer assigned)&#10;BSBTWK502 | Daniel Smith"></textarea>
  <div id="wfm-mapping-output"></div>
</div>

<div id="wfm-result" class="wfm-result-card" style="background:#f9fafb;border:2px solid #e5e7eb;">
  <p style="color:#6b7280;font-style:italic;margin:0;">Complete the inputs above to run the workforce check.</p>
</div>

<div id="wfm-alerts"></div>

<div id="wfm-trainer-output" style="margin-top:1rem;"></div>

<div id="wfm-compliance-section" style="display:none;">
  <h5 style="margin:1.25rem 0 0.5rem;font-weight:700;color:#374151;">Workforce Planning Summary</h5>
  <p style="font-size:0.88rem;color:#6b7280;margin:0 0 0.5rem;">A draft summary of the figures you entered, to use as a starting point for your own workforce plan or TAS (Section 6). Review it, confirm the numbers, and verify every trainer&#39;s vocational competency in the Trainer &amp; Assessor Register before treating it as evidence.</p>
  <div id="wfm-compliance-text" class="wfm-compliance-text"></div>
</div>

<script>
var WFM_RATIOS = { 'face-to-face': 20, 'online': 12, 'workplace': 15, 'mixed': 25 };
var WFM_MODE_LABELS = { 'face-to-face': 'Face-to-Face', 'online': 'Online', 'workplace': 'Workplace-Based', 'mixed': 'Mixed/Blended' };

function wfmParseUnits(raw) {
  var lines = raw.split('\n').map(function (l){ return l.trim(); }).filter(function (l){ return l.length > 0; });
  return lines.map(function (line) {
    var parts = line.split('|');
    var unit = parts[0] ? parts[0].trim() : '';
    var trainer = parts[1] ? parts[1].trim() : '';
    var isGap = !trainer || trainer.toLowerCase().indexOf('no trainer') !== -1 || trainer === '-' || trainer === '';
    return { unit: unit, trainer: isGap ? '' : trainer, gap: isGap };
  }).filter(function (r){ return r.unit !== ''; });
}

function wfmRun() {
  var trainers = parseInt(document.getElementById('wfm-trainers').value) || 0;
  var students = parseInt(document.getElementById('wfm-students').value) || 0;
  var mode = document.getElementById('wfm-mode').value;
  var fte = parseInt(document.getElementById('wfm-fte').value) || 20;
  var quals = parseInt(document.getElementById('wfm-quals').value) || 1;
  var assessPerQual = parseInt(document.getElementById('wfm-assess').value) || 5;
  var markingTime = parseFloat(document.getElementById('wfm-assess-type').value) || 2;
  var deliveryWeeks = parseInt(document.getElementById('wfm-weeks').value) || 10;
  var capacity = parseInt(document.getElementById('wfm-capacity').value) || 30;
  var unitRaw = document.getElementById('wfm-unit-map').value;

  if (!trainers || !students) return;

  var benchmark = WFM_RATIOS[mode] || 20;
  var modeLabel = WFM_MODE_LABELS[mode] || mode;

  // Trainer load calculation
  var ratio = students / trainers;
  var benchmarkCapacity = trainers * benchmark;
  var utilisation = Math.round((students / benchmarkCapacity) * 100);
  var trainersNeeded = Math.ceil(students / benchmark);

  // Assessment load — marking hours spread across delivery weeks (weekly average)
  var deliveryHours = quals * 10;
  var totalMarkingHours = students * assessPerQual * markingTime;
  var markingHours = Number((totalMarkingHours / deliveryWeeks).toFixed(2)); // weekly avg
  var supportHours = mode === 'online' ? students * 3 : students * 1;
  var totalLoad = deliveryHours + markingHours + supportHours;
  var hoursPerTrainer = trainers > 0 ? totalLoad / trainers : totalLoad;
  var trainerStatus = hoursPerTrainer <= capacity ? 'OK' : 'OVERLOADED';

  // Result card
  var colour, border, textcol, label, icon;
  if (students <= benchmarkCapacity * 0.8) {
    colour='#d1fae5'; border='#6ee7b7'; textcol='#065f46'; label='Sufficient Capacity'; icon='&#10003;';
  } else if (students <= benchmarkCapacity) {
    colour='#fef9c3'; border='#fde047'; textcol='#854d0e'; label='Near Capacity'; icon='&#9888;';
  } else {
    colour='#fee2e2'; border='#fca5a5'; textcol='#991b1b'; label='Over Benchmark'; icon='&#10005;';
  }

  var resultEl = document.getElementById('wfm-result');
  resultEl.style.background = colour; resultEl.style.borderColor = border;
  resultEl.innerHTML =
    '<div style="font-size:1.8rem;font-weight:700;color:'+textcol+';">' + icon + ' ' + label + '</div>' +
    '<div style="font-size:1.3rem;font-weight:600;color:'+textcol+';margin:6px 0;">Ratio: 1 : ' + ratio.toFixed(1) + ' (benchmark 1 : ' + benchmark + ')</div>' +
    '<table style="width:100%;font-size:0.88rem;border-collapse:collapse;margin-top:10px;">' +
    '<tr><td style="padding:4px 0;color:#374151;">Active trainers/assessors</td><td style="font-weight:600;text-align:right;">' + trainers + '</td></tr>' +
    '<tr><td style="padding:4px 0;color:#374151;">Enrolled students</td><td style="font-weight:600;text-align:right;">' + students + '</td></tr>' +
    '<tr><td style="padding:4px 0;color:#374151;">Trainers needed (benchmark)</td><td style="font-weight:600;text-align:right;color:' + (trainers >= trainersNeeded ? '#065f46' : '#991b1b') + ';">' + trainersNeeded + '</td></tr>' +
    '<tr><td style="padding:4px 0;color:#374151;">Benchmark capacity</td><td style="font-weight:600;text-align:right;">' + benchmarkCapacity + ' students</td></tr>' +
    '<tr><td style="padding:4px 0;color:#374151;">Utilisation</td><td style="font-weight:600;text-align:right;">' + utilisation + '%</td></tr>' +
    '<tr><td style="padding:4px 0;color:#374151;">Est. total trainer hours/week</td><td style="font-weight:600;text-align:right;">' + totalLoad.toFixed(0) + ' hrs (' + hoursPerTrainer.toFixed(1) + ' per trainer)</td></tr>' +
    '<tr><td style="padding:4px 0;color:#374151;">Trainer load status</td><td style="font-weight:600;text-align:right;color:' + (trainerStatus==='OK'?'#065f46':'#991b1b') + ';">' + (trainerStatus==='OK' ? '&#10003; OK' : '&#10005; Overloaded') + '</td></tr>' +
    '</table>';

  // Trainer load card
  var trainerOutEl = document.getElementById('wfm-trainer-output');
  trainerOutEl.innerHTML =
    '<div class="wfm-section"><h5>Trainer Load Breakdown</h5>' +
    '<table style="width:100%;font-size:0.88rem;border-collapse:collapse;">' +
    '<tr><th style="padding:7px;background:#f3f4f6;border:1px solid #e5e7eb;text-align:left;">Component</th><th style="padding:7px;background:#f3f4f6;border:1px solid #e5e7eb;text-align:right;">Hours/Week</th></tr>' +
    '<tr><td style="padding:7px;border:1px solid #e5e7eb;">Delivery hours ('+quals+' qual × 10 hrs)</td><td style="padding:7px;border:1px solid #e5e7eb;text-align:right;">' + deliveryHours.toFixed(0) + '</td></tr>' +
    '<tr><td style="padding:7px;border:1px solid #e5e7eb;">Marking hours weekly avg ('+students+' students × '+assessPerQual+' assessments × '+markingTime+' hrs ÷ '+deliveryWeeks+' weeks)<br><small style="color:#6b7280;">Total course marking: '+totalMarkingHours.toFixed(0)+' hrs</small></td><td style="padding:7px;border:1px solid #e5e7eb;text-align:right;">' + markingHours.toFixed(1) + '</td></tr>' +
    '<tr><td style="padding:7px;border:1px solid #e5e7eb;">Support hours ('+mode+' mode)</td><td style="padding:7px;border:1px solid #e5e7eb;text-align:right;">' + supportHours.toFixed(0) + '</td></tr>' +
    '<tr style="font-weight:700;"><td style="padding:7px;border:1px solid #e5e7eb;background:#f9fafb;">Total load / '+trainers+' trainers</td><td style="padding:7px;border:1px solid #e5e7eb;background:#f9fafb;text-align:right;">' + totalLoad.toFixed(0) + ' hrs ('+hoursPerTrainer.toFixed(1)+' each)</td></tr>' +
    '<tr><td style="padding:7px;border:1px solid #e5e7eb;">Trainer weekly capacity</td><td style="padding:7px;border:1px solid #e5e7eb;text-align:right;">' + capacity + ' hrs</td></tr>' +
    '</table></div>';

  // Alerts
  var alerts = [];
  if (trainerStatus === 'OVERLOADED') {
    alerts.push({ type: 'danger', msg: 'Trainers are overloaded — each trainer carries ' + hoursPerTrainer.toFixed(1) + ' hrs against a ' + capacity + '-hr capacity. Recruit additional staff or redistribute workload.' });
  }
  if (trainers < trainersNeeded) {
    alerts.push({ type: 'danger', msg: 'You need ' + trainersNeeded + ' trainer(s) for your current student load (' + students + ' students on ' + modeLabel + ' at 1:'+benchmark+' benchmark) but only have ' + trainers + '. Action: recruit or reduce intake.' });
  }
  if (students <= benchmarkCapacity && utilisation > 80) {
    alerts.push({ type: 'warn', msg: 'Approaching benchmark capacity (' + utilisation + '% utilised). Consider buffer capacity before taking on new enrolments.' });
  }
  if (alerts.length === 0) {
    alerts.push({ type: 'ok', msg: 'Workforce allocation is sufficient for the current student load.' });
  }

  // Unit mapping check
  var units = wfmParseUnits(unitRaw);
  var mappingAlerts = [];
  var mappingOutEl = document.getElementById('wfm-mapping-output');
  if (units.length > 0) {
    var gapCount = units.filter(function (u){ return u.gap; }).length;
    var tableHtml = '<h5 style="margin:1rem 0 0.5rem;font-weight:700;">Unit-to-Trainer Assignment</h5>' +
      '<table class="wfm-mapping-table"><thead><tr><th>Unit Code</th><th>Assigned Trainer</th><th>Status</th></tr></thead><tbody>';
    units.forEach(function (u) {
      var rowClass = u.gap ? 'gap-row' : 'ok-row';
      var status = u.gap ? '&#10005; GAP — no qualified trainer assigned' : '&#10003; Assigned';
      tableHtml += '<tr class="'+rowClass+'"><td>'+u.unit+'</td><td>'+(u.trainer||'<em>Not assigned</em>')+'</td><td>'+status+'</td></tr>';
    });
    tableHtml += '</tbody></table>';
    mappingOutEl.innerHTML = tableHtml;
    if (gapCount > 0) {
      mappingAlerts.push({ type: 'danger', msg: gapCount + ' unit(s) have no qualified trainer assigned — action required: recruit or assign a trainer with verified vocational competency for each gap unit.' });
    } else {
      mappingAlerts.push({ type: 'ok', msg: 'All ' + units.length + ' units have a qualified trainer assigned.' });
    }
  } else {
    mappingOutEl.innerHTML = '';
  }

  // Render alerts
  var allAlerts = alerts.concat(mappingAlerts);
  var alertsEl = document.getElementById('wfm-alerts');
  alertsEl.innerHTML = '<div style="margin-top:0.75rem;">' +
    allAlerts.map(function (a) { return '<div class="wfm-alert '+a.type+'">'+a.msg+'</div>'; }).join('') +
    '</div>';

  // Compliance statement
  var today = new Date().toLocaleDateString('en-AU', {day:'2-digit',month:'long',year:'numeric'});
  var gapUnits = units.filter(function (u){ return u.gap; });
  var compText = 'WORKFORCE PLANNING SUMMARY (DRAFT — for RTO review)\n';
  compText += 'Generated: ' + today + '\n\n';
  compText += 'This summary supports the RTO\'s workforce planning under Standard 3.1 of the Standards for Registered Training Organisations (RTOs) 2025. It is a planning worksheet based on the figures entered below and does not by itself demonstrate compliance — the RTO must confirm the data and hold the underlying evidence.\n\n';
  compText += 'CURRENT WORKFORCE STATUS\n';
  compText += 'Delivery mode: ' + modeLabel + '\n';
  compText += 'Active trainers/assessors: ' + trainers + '\n';
  compText += 'Enrolled students: ' + students + '\n';
  compText += 'Current ratio: 1 : ' + ratio.toFixed(1) + ' (indicative planning benchmark 1 : ' + benchmark + ' — not an ASQA-mandated ratio)\n';
  compText += 'Benchmark utilisation: ' + utilisation + '%\n\n';
  compText += 'TRAINER LOAD ANALYSIS\n';
  compText += 'Based on current data, ' + trainersNeeded + ' trainer(s) are required to support delivery. The current workforce of ' + trainers + ' trainer(s) carries an estimated load of ' + hoursPerTrainer.toFixed(1) + ' hours per trainer per week (delivery: ' + deliveryHours + ' hrs, marking weekly avg: ' + markingHours.toFixed(1) + ' hrs [total course marking: ' + totalMarkingHours.toFixed(0) + ' hrs spread over ' + deliveryWeeks + ' weeks], student support: ' + supportHours.toFixed(0) + ' hrs) against a weekly capacity of ' + capacity + ' hours per trainer.\n\n';
  compText += 'WORKFORCE STATUS: ' + (trainerStatus === 'OK' ? 'SUFFICIENT' : 'ACTION REQUIRED') + '\n';
  if (trainerStatus === 'OVERLOADED') {
    compText += 'ACTION: Trainer workload exceeds capacity. Immediate recruitment or workload redistribution is required to maintain quality outcomes.\n\n';
  } else {
    compText += 'The RTO has sufficient trainer capacity to maintain quality training and assessment outcomes for the current cohort.\n\n';
  }
  if (units.length > 0) {
    compText += 'UNIT-TO-TRAINER MAPPING\n';
    compText += 'Total units in scope: ' + units.length + '\n';
    compText += 'Units with assigned trainer: ' + units.filter(function (u){return !u.gap;}).length + '\n';
    compText += 'Coverage gaps: ' + gapUnits.length + '\n';
    if (gapUnits.length > 0) {
      compText += 'Gap units: ' + gapUnits.map(function (u){return u.unit;}).join(', ') + '\n';
      compText += 'ACTION: Assign a trainer to each gap unit and verify their vocational competency in the Trainer & Assessor Register.\n';
    } else {
      compText += 'A trainer has been nominated against every unit above. These assignments are self-reported on this page and are NOT verified here — confirm each trainer\'s vocational competency and currency against the Trainer & Assessor Register before relying on this summary.\n';
    }
  }
  compText += '\nDRAFT worksheet generated by the RTO Compliance plugin from the figures entered above. It is a planning aid only. On its own it is not audit evidence — the RTO must review the figures, verify trainer credentials in the Trainer & Assessor Register, and retain the supporting records.';

  document.getElementById('wfm-compliance-text').textContent = compText;
  document.getElementById('wfm-compliance-section').style.display = 'block';
}
wfmRun();
</script>
HTML;
echo html_writer::end_div();

echo html_writer::start_div('', ['style' => 'margin-top:2rem;padding:1.5rem;background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;']);
echo html_writer::tag('h4', 'Related pages', ['style' => 'margin:0 0 0.75rem;color:#0369a1;']);
echo '<div style="display:flex;flex-wrap:wrap;gap:0.75rem;">';
echo '<a href="' . (new moodle_url('/local/rtocompliance/trainers.php'))->out() . '" class="btn btn-outline-primary btn-sm" title="Open the Trainer and Assessor Register, where vocational competency is verified">Trainer & Assessor Register</a>';
echo '<a href="' . (new moodle_url('/local/rtocompliance/supervision.php'))->out() . '" class="btn btn-outline-primary btn-sm" title="Open the Supervision and Direction Log">Supervision Log</a>';
echo '<a href="' . (new moodle_url('/local/rtocompliance/thirdparty.php'))->out() . '" class="btn btn-outline-primary btn-sm" title="Open Third-Party Arrangements">Third-Party Arrangements</a>';
echo '<a href="' . (new moodle_url('/local/rtocompliance/qualbuilder.php'))->out() . '" class="btn btn-outline-primary btn-sm" title="Open the Qualification Builder">Qualification Builder</a>';
echo '<a href="' . (new moodle_url('/local/rtocompliance/tas.php'))->out() . '" class="btn btn-outline-primary btn-sm" title="Open the TAS Generator">TAS Generator</a>';
echo '</div>';
echo html_writer::end_div();

echo html_writer::end_div();

echo $OUTPUT->footer();
