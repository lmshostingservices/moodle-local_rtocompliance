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
 * RTO Compliance plugin — how_it_works.php.
 *
 * @package    local_rtocompliance
 * @copyright  2025 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * HOW IT WORKS (v5.9.399)
 *
 * A plain-language, high-level explainer of the whole RTO Compliance system for
 * someone who has never seen it before. No jargon. This is the first thing a new
 * user should read.
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/lib.php');

admin_externalpage_setup('local_rtocompliance_howitworks');
require_login();
require_capability('local/rtocompliance:manage', context_system::instance());

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/rtocompliance/how_it_works.php'));
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('howitworks_title', 'local_rtocompliance'));
$PAGE->set_heading(get_string('howitworks_title', 'local_rtocompliance'));
$PAGE->requires->css('/local/rtocompliance/styles.css');
$PAGE->add_body_class('path-local-rtocompliance');

echo $OUTPUT->header();
// Render the plugin's own left sidebar + breadcrumb, consistent with every
// other RTO Compliance page.
echo local_rtocompliance_render_nav_header(get_string('howitworks_title', 'local_rtocompliance'));

// Prominent link to the quick-answer FAQ.
$faq_url = (new moodle_url('/local/rtocompliance/faq.php'))->out();
echo '<a href="' . $faq_url . '" style="display:flex;align-items:center;gap:14px;text-decoration:none;'
    . 'background:linear-gradient(135deg,#eef2ff,#faf5ff);border:1px solid #e0e7ff;border-radius:12px;'
    . 'padding:16px 20px;margin:0 0 20px;max-width:900px;">'
    . '<span style="flex:0 0 auto;width:42px;height:42px;border-radius:11px;background:linear-gradient(135deg,#4f46e5,#7c3aed);'
    . 'color:#fff;display:flex;align-items:center;justify-content:center;box-shadow:0 6px 14px -4px rgba(79,70,229,.5);">'
    . '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 10h8M8 14h5"/><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg></span>'
    . '<span style="flex:1;"><span style="display:block;font-size:16px;font-weight:800;color:#3730a3;">Have a specific question? Read the FAQ</span>'
    . '<span style="display:block;font-size:13.5px;color:#475569;margin-top:2px;">100 quick, plain-English answers grouped into 20 topics.</span></span>'
    . '<span style="flex:0 0 auto;color:#6366f1;font-weight:700;font-size:14px;">Open FAQ &rarr;</span>'
    . '</a>';

// Everything inline so it renders identically regardless of theme.
$qbuilder = (new moodle_url('/local/rtocompliance/qualbuilder.php'))->out();
$coursemap = (new moodle_url('/local/rtocompliance/course_map.php'))->out();
$results = (new moodle_url('/local/rtocompliance/qualbuilder_results.php'))->out();
$dataimport = (new moodle_url('/local/rtocompliance/data_import.php'))->out();

?>
<style>
.hiw-wrap{max-width:920px;margin:0 auto;padding:8px 4px 40px;color:#1f2937;
  font:15px/1.65 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;}
.hiw-hero{background:linear-gradient(135deg,#1e3a8a,#2563eb);color:#fff;border-radius:16px;
  padding:28px 30px;margin-bottom:22px;box-shadow:0 6px 20px rgba(30,58,138,.25);}
.hiw-hero h1{margin:0 0 6px;font-size:26px;font-weight:800;color:#fff;}
.hiw-hero p{margin:0;font-size:16px;color:#dbeafe;}
.hiw-oneline{background:#eff6ff;border:1px solid #bfdbfe;border-radius:12px;padding:16px 20px;
  margin-bottom:26px;font-size:16px;color:#1e3a8a;}
.hiw-oneline b{color:#1d4ed8;}
.hiw-h2{font-size:18px;font-weight:800;margin:30px 0 12px;color:#111827;}
.hiw-cards{display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px;margin-bottom:8px;}
@media(max-width:760px){.hiw-cards{grid-template-columns:1fr;}}
.hiw-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:16px 18px;
  box-shadow:0 1px 3px rgba(0,0,0,.06);}
.hiw-card .ico{font-size:26px;line-height:1;margin-bottom:8px;}
.hiw-card h3{margin:0 0 6px;font-size:15.5px;font-weight:700;color:#111827;}
.hiw-card p{margin:0;font-size:14px;color:#4b5563;}
.hiw-flow{background:#f8fafc;border:1px solid #e5e7eb;border-radius:12px;padding:6px 4px;margin:6px 0 8px;}
.hiw-step{display:flex;gap:16px;align-items:flex-start;padding:16px 18px;border-bottom:1px solid #eef2f7;}
.hiw-step:last-child{border-bottom:none;}
.hiw-num{flex:0 0 34px;height:34px;border-radius:50%;background:#2563eb;color:#fff;font-weight:800;
  display:flex;align-items:center;justify-content:center;font-size:15px;}
.hiw-step h3{margin:0 0 3px;font-size:15.5px;font-weight:700;color:#111827;}
.hiw-step p{margin:0;font-size:14px;color:#4b5563;}
.hiw-two{background:#f0fdf4;border:1px solid #bbf7d0;border-radius:12px;padding:16px 20px;margin:6px 0;}
.hiw-two h3{margin:0 0 4px;font-size:15px;font-weight:700;color:#166534;}
.hiw-two p{margin:0 0 8px;font-size:14px;color:#3f6212;}
.hiw-do{counter-reset:step;list-style:none;margin:8px 0 0;padding:0;}
.hiw-do li{position:relative;padding:10px 0 10px 40px;border-bottom:1px dashed #e5e7eb;font-size:14.5px;}
.hiw-do li:last-child{border-bottom:none;}
.hiw-do li::before{counter-increment:step;content:counter(step);position:absolute;left:0;top:9px;
  width:26px;height:26px;border-radius:50%;background:#dbeafe;color:#1d4ed8;font-weight:800;
  display:flex;align-items:center;justify-content:center;font-size:13px;}
.hiw-do a{font-weight:600;text-decoration:none;color:#1d4ed8;}
.hiw-note{color:#6b7280;font-size:13px;margin-top:18px;}
.hiw-arrow{text-align:center;color:#94a3b8;font-size:20px;margin:2px 0;}
.hiw-qa{display:grid;gap:10px;margin:6px 0 4px;}
.hiw-q{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:14px 16px;
  box-shadow:0 1px 2px rgba(0,0,0,.04);}
.hiw-q .q{font-weight:700;font-size:14.5px;color:#111827;margin-bottom:4px;display:flex;gap:8px;}
.hiw-q .q .qmark{color:#2563eb;font-weight:800;}
.hiw-q .a{font-size:14px;color:#4b5563;padding-left:20px;}
.hiw-q .a a{color:#1d4ed8;font-weight:600;text-decoration:none;}
.hiw-q .a a:hover{text-decoration:underline;}
</style>

<div class="hiw-wrap">

  <div class="hiw-hero">
    <h1>How RTO Compliance Works</h1>
    <p>A plain-English overview — no jargon. Read this first.</p>
  </div>

  <div class="hiw-oneline">
    In one sentence: this system keeps a <b>record of which training units each student has completed</b>,
    then uses that record to <b>issue certificates</b> and <b>produce your government (AVETMISS) reports</b>.
    It only <b>reads</b> what happens in Moodle &mdash; it never creates accounts, enrolments or completions itself.
  </div>

  <div class="hiw-h2">The three building blocks</div>
  <div class="hiw-cards">
    <div class="hiw-card">
      <div class="ico">&#128218;</div>
      <h3>1. Qualifications &amp; units</h3>
      <p>Every qualification is made up of smaller <b>units</b>. Think of it as the recipe: the qualification
         is the finished dish, the units are the ingredients. You set this up once in the
         <b>Qualification Builder</b>.</p>
    </div>
    <div class="hiw-card">
      <div class="ico">&#127891;</div>
      <h3>2. Courses in Moodle</h3>
      <p>This is where students actually learn. Each Moodle <b>course</b> teaches one or more units. Students
         work through the course and finish it — that's a <b>completion</b>.</p>
    </div>
    <div class="hiw-card">
      <div class="ico">&#128203;</div>
      <h3>3. The results record</h3>
      <p>The system's own record book. When a student completes a course, it writes down which
         <b>units</b> that student is now competent in. Everything else reads from this record.</p>
    </div>
  </div>

  <div class="hiw-h2">How they connect</div>
  <div class="hiw-flow">
    <div class="hiw-step">
      <div class="hiw-num">1</div>
      <div>
        <h3>You link each unit to the course that teaches it</h3>
        <p>In the Qualification Builder you tell the system: &ldquo;this unit is taught by that Moodle course.&rdquo;
           This link is the one and only bridge between Moodle and the compliance system.</p>
      </div>
    </div>
    <div class="hiw-arrow">&#8595;</div>
    <div class="hiw-step">
      <div class="hiw-num">2</div>
      <div>
        <h3>A student finishes a course</h3>
        <p>The system sees the completion, looks up which unit(s) that course teaches, and marks the student
           <b>competent</b> in those units — in the results record.</p>
      </div>
    </div>
    <div class="hiw-arrow">&#8595;</div>
    <div class="hiw-step">
      <div class="hiw-num">3</div>
      <div>
        <h3>When all a qualification's units are done</h3>
        <p>The student has completed the qualification, so the system can <b>issue their certificate</b> and
           include them in your <b>AVETMISS reporting</b>.</p>
      </div>
    </div>
  </div>

  <div class="hiw-h2">Two ways results get into the record</div>
  <div class="hiw-two">
    <h3>&#9889; Live &mdash; students learning now</h3>
    <p>Students completing Moodle courses today. Their results flow in automatically.</p>
    <h3>&#128230; History &mdash; your older records</h3>
    <p>Results from before this system (or from an older system) can be imported from your data files, so a
       student's full history sits in one place alongside their new results.</p>
  </div>

  <div class="hiw-h2">What you get out of it</div>
  <div class="hiw-cards">
    <div class="hiw-card">
      <div class="ico">&#128202;</div>
      <h3>Student Results</h3>
      <p>One big table showing every student and the units they've completed. This is the main screen you'll
         look at day to day.</p>
    </div>
    <div class="hiw-card">
      <div class="ico">&#127942;</div>
      <h3>Certificates</h3>
      <p>Testamurs and Statements of Attainment, generated from the results record when a student is eligible.</p>
    </div>
    <div class="hiw-card">
      <div class="ico">&#128196;</div>
      <h3>AVETMISS reports</h3>
      <p>The government reporting files, produced straight from the same results record.</p>
    </div>
  </div>

  <div class="hiw-h2">Getting started &mdash; the simple order</div>
  <ol class="hiw-do">
    <li>Build your qualifications and their units in the <a href="<?php echo $qbuilder; ?>">Qualification Builder</a>.</li>
    <li>Check each unit is linked to the Moodle course that teaches it, then click <b>&ldquo;Build Course Map from Links&rdquo;</b> in the <a href="<?php echo $qbuilder; ?>">Qualification Builder</a> so the <a href="<?php echo $coursemap; ?>">Course Map</a> is filled from those links (this is what lets completions and certificates find the right courses).</li>
    <li>Bring in completions &mdash; live ones flow in automatically; older ones can be imported in <a href="<?php echo $dataimport; ?>">Data Import</a>.</li>
    <li>Open <a href="<?php echo $results; ?>">Student Results</a> to see everyone's progress in one table.</li>
    <li>Issue certificates to students who have finished, and export your AVETMISS reports when it's time.</li>
  </ol>

  <div class="hiw-h2">Key questions &mdash; quick answers</div>
  <div class="hiw-qa">
    <div class="hiw-q">
      <div class="q"><span class="qmark">Q.</span> How do I check if we're audit-ready?</div>
      <div class="a">Open <a href="<?php echo (new moodle_url('/local/rtocompliance/compliance_health.php'))->out(); ?>">Compliance Health</a> (top of the left menu). It gives you an audit-readiness score and a card for each quality area &mdash; overdue validations, trainer deadlines, unverified USIs, incomplete profiles, complaints and more &mdash; each with a one-click link to fix it.</div>
    </div>
    <div class="hiw-q">
      <div class="q"><span class="qmark">Q.</span> How do I make sure my NAT export won't be rejected?</div>
      <div class="a">Run <a href="<?php echo (new moodle_url('/local/rtocompliance/nat_validate.php'))->out(); ?>">AVETMISS Validation</a> (under Data &amp; Reporting) before you export. It checks every student and enrolment against the NCVER rules, splits problems into Errors and Warnings, and gives you a &ldquo;Ready to submit&rdquo; verdict &mdash; so you fix issues here instead of after the collection system bounces them.</div>
    </div>
    <div class="hiw-q">
      <div class="q"><span class="qmark">Q.</span> How do students get their certificates?</div>
      <div class="a">Students download their own certificates from the <a href="<?php echo (new moodle_url('/local/rtocompliance/mycerts.php'))->out(); ?>">My Certificates</a> portal. They can reach it from a &ldquo;My Certificates&rdquo; link in their navigation, a companion &ldquo;My Certificates&rdquo; dashboard block (installed separately), or the link on their profile page &mdash; no need to email PDFs out one by one.</div>
    </div>
    <div class="hiw-q">
      <div class="q"><span class="qmark">Q.</span> How is each ASQA Standard covered?</div>
      <div class="a">See <a href="<?php echo (new moodle_url('/local/rtocompliance/asqa_standards_map.php'))->out(); ?>">ASQA Compliance Mapping</a>. It lists every 2025 Standard (QA1&ndash;QA4) next to the plugin feature that supports it, with an honest Covered / Partial / Gap status &mdash; and doubles as self-assurance evidence.</div>
    </div>
    <div class="hiw-q">
      <div class="q"><span class="qmark">Q.</span> How do I view a student's AVETMISS data?</div>
      <div class="a">Open <a href="<?php echo (new moodle_url('/local/rtocompliance/students.php'))->out(); ?>">Student Records</a> and click a student to see their AVETMISS profile &mdash; demographics, USI, prior education and address. Their unit outcomes are on <a href="<?php echo $results; ?>">Student Results</a>.</div>
    </div>
    <div class="hiw-q">
      <div class="q"><span class="qmark">Q.</span> How do I issue a qualification (Testamur)?</div>
      <div class="a">Go to <a href="<?php echo $results; ?>">Student Results</a>, open the qualification, and click <strong>Issue Certificate</strong> for a student whose units are all complete &mdash; or use the <a href="<?php echo (new moodle_url('/local/rtocompliance/qual_cert_hub.php'))->out(); ?>">Qualification Certificate Hub</a> to issue in bulk.</div>
    </div>
    <div class="hiw-q">
      <div class="q"><span class="qmark">Q.</span> How do I issue a multi-unit Statement of Attainment?</div>
      <div class="a">Use <a href="<?php echo (new moodle_url('/local/rtocompliance/soa_issue.php'))->out(); ?>">Issue Multi-Unit SOA</a> (under Certificates). Pick the student, choose the units they've completed, and generate the statement.</div>
    </div>
    <div class="hiw-q">
      <div class="q"><span class="qmark">Q.</span> How do I export my NAT / AVETMISS records?</div>
      <div class="a">Go to <a href="<?php echo (new moodle_url('/local/rtocompliance/natexport.php'))->out(); ?>">AVETMISS Export</a> (under Data &amp; Reporting). It builds the NAT files for your regulator straight from the results register.</div>
    </div>
    <div class="hiw-q">
      <div class="q"><span class="qmark">Q.</span> How do I bring in past completions and older records?</div>
      <div class="a">On <a href="<?php echo $results; ?>">Student Results</a> click <strong>Sync results from Moodle completions</strong> to pull in Moodle completions, and use <a href="<?php echo $dataimport; ?>">Data Import</a> to load historical NAT files from an older system.</div>
    </div>
    <div class="hiw-q">
      <div class="q"><span class="qmark">Q.</span> How do I set up a qualification and its units?</div>
      <div class="a">Open the <a href="<?php echo $qbuilder; ?>">Qualification Builder</a>, add the qualification (fetch its units from training.gov.au), and link each unit to the Moodle course that teaches it. Nominal hours resolve automatically from the plugin's authoritative reference table and roll up to a qualification total.</div>
    </div>
    <div class="hiw-q">
      <div class="q"><span class="qmark">Q.</span> How do I record RPL or a credit transfer?</div>
      <div class="a">Open <a href="<?php echo (new moodle_url('/local/rtocompliance/rpl.php'))->out(); ?>">RPL &amp; Credit Transfer</a>, add a record for the student and unit, choose the assessor from your registered trainers (the form checks their TAE currency), attach the evidence and map it to the unit criteria, and record the decision. An approved decision posts the competent outcome straight into Student Results, certificates and your NAT export.</div>
    </div>
    <div class="hiw-q">
      <div class="q"><span class="qmark">Q.</span> How do I check a student was properly pre-enrolled?</div>
      <div class="a">Open the student from <a href="<?php echo (new moodle_url('/local/rtocompliance/students.php'))->out(); ?>">Student Records</a> — their profile shows a pre-enrolment readiness card with four gates: suitability assessed, student declaration signed, USI verified, and information provided. <a href="<?php echo (new moodle_url('/local/rtocompliance/compliance_health.php'))->out(); ?>">Compliance Health</a> flags any student who has results but no completed suitability review.</div>
    </div>
    <div class="hiw-q">
      <div class="q"><span class="qmark">Q.</span> Where do nominal hours come from?</div>
      <div class="a">training.gov.au does not publish nominal hours, so the plugin holds its own authoritative reference table. Load the NCVER nationally-agreed dataset via <a href="<?php echo (new moodle_url('/local/rtocompliance/nominalhours_import.php'))->out(); ?>">Import Nominal Hours</a>; the Qualification Builder total, AVETMISS NAT00120 scheduled hours and the TAS volume of learning all resolve from it.</div>
    </div>
  </div>

  <p class="hiw-note">That's the whole picture. Every other screen in this section is just a more detailed
     view of one of these steps.</p>

</div>
<?php

echo $OUTPUT->footer();
