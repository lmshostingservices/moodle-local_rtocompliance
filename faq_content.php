<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify it under the terms of
// the GNU General Public License as published by the Free Software Foundation, either
// version 3 of the License, or (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY.
// See the GNU General Public License for more details. You should have received a copy of
// the GNU GPL along with Moodle. If not, see <http://www.gnu.org/licenses/>.

/**
 * RTO Compliance plugin — FAQ content (single source of truth).
 *
 * 100 plain-English questions across 20 categories, written for a brand-new day-1 user.
 * Rendered by faq.php AND fed into the AI assistant's knowledge base, so the assistant is
 * automatically trained on every FAQ answer. Each item: q (question), a (answer, simple
 * language), page (optional plugin page-file the answer links to).
 *
 * @package    local_rtocompliance
 * @copyright  2026 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die();

/**
 * @return array List of ['cat' => string, 'icon' => string, 'items' => [['q','a','page'],...]]
 */
function local_rtocompliance_faq_data(): array {
    return [
        ['cat' => 'Getting started', 'icon' => 'rocket', 'items' => [
            ['q' => 'What does this plugin actually do?', 'a' => 'It helps your RTO stay compliant with the Standards for RTOs 2025. In one place it manages your students and AVETMISS data, qualifications and units, results and completions, certificates, USI verification, trainers, governance and NAT reporting.', 'page' => 'index.php'],
            ['q' => 'Where do I start after installing it?', 'a' => 'Follow this simple order: set up your RTO details, build your qualifications, link your Moodle courses, add your students, then issue certificates. The How It Works page walks you through it.', 'page' => 'how_it_works.php'],
            ['q' => 'How do I set up my RTO details?', 'a' => 'Go to Plugin Settings and enter your legal name, national provider code (RTO/TOID) and the person who signs certificates. These flow onto every certificate and report, so get them right once.', 'page' => 'plugin_settings.php'],
            ['q' => 'Will this change my Moodle courses, accounts or enrolments?', 'a' => 'No. The plugin only READS Moodle (enrolments, course completions, users, categories) and writes to its own tables. It never creates, edits or deletes Moodle courses, accounts or enrolments.', 'page' => ''],
            ['q' => 'What is the fastest way to get help?', 'a' => 'Ask the AI Assistant (bottom-right of any plugin page — 1 credit per question), read this FAQ, or open the Help card at the top of each page. Every page also explains itself in plain English.', 'page' => 'faq.php'],
            ['q' => 'Who created RTO Compliance?', 'a' => 'Bronwyn Blencowe was the principal researcher and advisor for RTO Compliance. She is a consultant with over 30 years in the Australian VET industry, and brought her wealth of knowledge about RTOs and ASQA compliance into this software.', 'page' => ''],
        ]],
        ['cat' => 'Qualifications & units', 'icon' => 'award', 'items' => [
            ['q' => 'How do I add a qualification?', 'a' => 'Open the Qualification Builder and click "Add Training Product". Enter the qualification code and load its units and packaging rules straight from training.gov.au.', 'page' => 'qualbuilder.php'],
            ['q' => 'What is a "variant" or "stream"?', 'a' => 'It is a specific version or intake of the same qualification — for example a semester (2026 S1) or an archived year. Products with the same code but different variants are kept as separate rows.', 'page' => 'qualbuilder.php'],
            ['q' => 'How do I create a separate product for each semester intake?', 'a' => 'Use the Semester Intake Builder. It scans your Moodle category tree and creates one draft product per intake, with that intake\'s units and courses attached.', 'page' => 'qualbuilder_semester.php'],
            ['q' => 'What do the Units, Linked Courses and Course Map columns mean?', 'a' => 'Units = how many units make up the qualification. Linked Courses = how many of those units have a Moodle delivery course attached. Course Map = how many are confirmed in the course-to-unit map used for certificates.', 'page' => 'qualbuilder.php'],
            ['q' => 'Why does a product show "Course Map: None"?', 'a' => 'The course map has not been built for it yet. Click "Build Course Map from Links" at the top of the Qualification Builder — it fills the map from the courses already linked to each unit.', 'page' => 'qualbuilder.php'],
        ]],
        ['cat' => 'Linking Moodle courses', 'icon' => 'link', 'items' => [
            ['q' => 'How do I link my Moodle courses to units?', 'a' => 'The quickest way is the "Build Course Map from Links" button in the Qualification Builder. You can also open the Moodle Course Map and click "Scan & Seed All Quals" to auto-detect the links.', 'page' => 'course_map.php'],
            ['q' => 'What is the Course Map?', 'a' => 'It is the single source of truth that maps every Moodle course to a qualification code and unit code. Certificates, completion detection and reporting all read from it, so nothing has to guess from course names.', 'page' => 'course_map.php'],
            ['q' => 'What is the difference between Confirmed and Unconfirmed mappings?', 'a' => 'Confirmed means a person has reviewed and accepted the mapping. Unconfirmed means it was auto-detected and is waiting for you to confirm it (click the green tick).', 'page' => 'course_map.php'],
            ['q' => 'A course was not detected automatically — how do I map it?', 'a' => 'On the Course Map page use "Add Manual Mapping": enter the qualification code, the unit code and the Moodle course ID, then click Add Mapping.', 'page' => 'course_map.php'],
            ['q' => 'Does the map recognise old or archived copies of a course?', 'a' => 'Yes. Archive and semester-copy courses are treated as legitimate deliveries of the unit, so completions in those older courses are still credited.', 'page' => 'course_map.php'],
        ]],
        ['cat' => 'Students & enrolments', 'icon' => 'users', 'items' => [
            ['q' => 'Where do I see all my students?', 'a' => 'Open Student Records for the master roster of every student, with search and filters for status, state and USI health.', 'page' => 'students.php'],
            ['q' => 'How do I add a student\'s enrolment or record an outcome?', 'a' => 'Open a student and use Enrolments to add or edit an enrolment, or record a unit outcome (Competent, Not Yet Competent, RPL or Credit Transfer).', 'page' => 'student_enrolments.php'],
            ['q' => 'What do the outcome codes mean?', 'a' => 'C = Competent (20), NYC = Not Yet Competent (30), RPL = Recognition of Prior Learning (51/52), CT = Credit Transfer (60). These are the AVETMISS outcome identifiers.', 'page' => 'qualbuilder_results.php'],
            ['q' => 'A student is missing their date of birth or USI — how do I fix it?', 'a' => 'Filter the roster for the gap (e.g. "USI Present, DOB Missing"), open the student, and complete the missing AVETMISS fields. Students can also complete their own details from their profile.', 'page' => 'students.php'],
            ['q' => 'How do I check a student is suitable/ready before enrolling?', 'a' => 'Use the Student Suitability Check to record LLN, prerequisites and the enrolment decision, and send the student a suitability declaration.', 'page' => 'suitability_view.php'],
        ]],
        ['cat' => 'USI verification', 'icon' => 'shield', 'items' => [
            ['q' => 'How do I verify a student\'s USI?', 'a' => 'Open the student in Student Records and click "Verify USI". The plugin checks the USI against the USI Registry through the lms-labs.com platform using your RTO\'s credential.', 'page' => 'students.php'],
            ['q' => 'Where are my USI credentials stored?', 'a' => 'Only on the lms-labs.com platform — never in Moodle. Your myID Machine Credential keystore and password are uploaded and rotated in the platform admin panel for security.', 'page' => 'usi_settings.php'],
            ['q' => 'How do I check my USI setup is working?', 'a' => 'Open the USI Verification page. It shows a read-only status (Ready / Not ready / Expired) and whether the connection to the platform is healthy.', 'page' => 'usi_settings.php'],
            ['q' => 'Why can I not verify a USI?', 'a' => 'Common reasons: the Platform API details are not set in Plugin Settings, the credential is not configured on the platform, or the USI name/date of birth do not match the Registry. The status page tells you which.', 'page' => 'usi_settings.php'],
            ['q' => 'Do I need a verified USI before issuing a certificate?', 'a' => 'Yes. AQF certificates require a verified USI (or a recorded exemption/override). Verify the USI first, then issue.', 'page' => 'students.php'],
            ['q' => 'USI verification fails with error E2003 ("relying party not recognised") — how do I fix it?', 'a' => 'This is an ATO authorisation step, not a plugin or platform fault. It means your machine credential is valid and reaches the ATO, but has not yet been authorised to access the USI Registry service. Fix: a Principal Authority for your ABN signs in to ATO Access Manager (am.ato.gov.au) with their myGovID and authorises your machine credential for the USI Registry service — or calls RAM support on 1300 287 539 (option 3, then option 1) with your TOID and the credential name. It takes effect within minutes to 24 hours, after which Verify USI works.', 'page' => 'usi_settings.php'],
        ]],
        ['cat' => 'AVETMISS & NAT import', 'icon' => 'upload', 'items' => [
            ['q' => 'How do I import my NAT files?', 'a' => 'Open Data Import and upload your AVETMISS NAT file set. Review the students grouped by qualification and semester, then confirm to write them into your register.', 'page' => 'data_import.php'],
            ['q' => 'Which NAT files can I import?', 'a' => 'The full AVETMISS set including demographics (NAT00080), disability (NAT00090), prior education (NAT00100), activity (NAT00120) and completions (NAT00130). Disability and prior-education detail are written back onto matching students.', 'page' => 'data_import.php'],
            ['q' => 'Will importing create Moodle accounts or enrolments?', 'a' => 'No. Import only writes to the plugin\'s own student and results tables. It never creates or changes Moodle accounts or enrolments.', 'page' => 'data_import.php'],
            ['q' => 'What is AVETMISS?', 'a' => 'It is the national data standard for VET. Your student, enrolment and outcome data is reported to your State Training Authority and NCVER in the AVETMISS "NAT" file format.', 'page' => 'data_import.php'],
            ['q' => 'A re-import seemed to lose disability/prior-education data — is that fixed?', 'a' => 'Yes. The importer round-trips NAT00090 and NAT00100 onto the matching live students, so a re-import no longer drops that detail.', 'page' => 'data_import.php'],
        ]],
        ['cat' => 'NAT export & reporting', 'icon' => 'download', 'items' => [
            ['q' => 'How do I generate my NAT files for reporting?', 'a' => 'Use the NAT Export page. Validate first, then generate the NAT files ready to submit to your State Training Authority or NCVER.', 'page' => 'natexport.php'],
            ['q' => 'How do I check my data before submitting?', 'a' => 'Run the AVETMISS Validation. It checks every reportable student and enrolment against the NCVER edit rules and gives you a "ready to submit / N errors" verdict.', 'page' => 'nat_validate.php'],
            ['q' => 'Where do my RTO organisation details for NAT00010 come from?', 'a' => 'From Plugin Settings (RTO Details). Make sure your legal name and provider code are set before you export.', 'page' => 'plugin_settings.php'],
            ['q' => 'What is the difference between an ERROR and a WARNING in validation?', 'a' => 'Errors will fail the NCVER submission and must be fixed. Warnings are worth reviewing but will not stop your submission.', 'page' => 'nat_validate.php'],
            ['q' => 'Completions are missing from my export — what do I do?', 'a' => 'Run "Sync from completions" on Student Results, and use the Reconcile tool to trace unmatched completions and course-map gaps.', 'page' => 'reconcile.php'],
        ]],
        ['cat' => 'Issuing certificates', 'icon' => 'certificate', 'items' => [
            ['q' => 'How do I issue a certificate to a student?', 'a' => 'For a full qualification use Generate Qualification Certificates (issues a Testamur + Record of Results). For a single student use Issue Certificate. For one unit/course use Generate Course Certificates.', 'page' => 'generate_qual_certs.php'],
            ['q' => 'How much does a certificate cost?', 'a' => 'Every certificate costs 5 credits (about A$0.50). You are shown the total cost before you generate, and nothing is issued for free — automatic issuance is charged the same way.', 'page' => 'qual_cert_hub.php'],
            ['q' => 'What is the Qualification Certificate Hub?', 'a' => 'A dashboard showing, per qualification and variant, how many students are enrolled, complete, issued, pending, or queued for automatic issue — with one-click actions to issue them.', 'page' => 'qual_cert_hub.php'],
            ['q' => 'What does the "Queue" column mean?', 'a' => 'Students lined up for automatic certificate issue. They are picked up by the autocert process, or you can click Process Queue on the qualification\'s Detail page.', 'page' => 'qual_cert_hub.php'],
            ['q' => 'Why can I not issue a certificate for a student?', 'a' => 'Usually because the USI is not verified, or the required RTO details are not set, or the student has not completed every required unit. Fix the flagged item, then issue.', 'page' => 'qual_cert_hub.php'],
        ]],
        ['cat' => 'Certificate templates', 'icon' => 'template', 'items' => [
            ['q' => 'How do I design my certificate?', 'a' => 'Open Certificate Templates and edit a template in the drag-and-drop editor. You can place your logo, signatures, the units table and other fields.', 'page' => 'cert_templates.php'],
            ['q' => 'What is on a Record of Results?', 'a' => 'A shaded three-column units table — Unit Code, Unit Title and Completion Date — with a header colour you set in Certificate Settings.', 'page' => 'cert_templates.php'],
            ['q' => 'Can I preview a certificate before issuing real ones?', 'a' => 'Yes. Use Test Certificate to generate a sample with a test student name so you can check the layout.', 'page' => 'cert_test.php'],
            ['q' => 'My template will not save — what do I do?', 'a' => 'Purge caches (Site administration → Development → Purge all caches) and reload the editor. The design is also saved server-side so a save never posts an empty layout.', 'page' => 'cert_templates.php'],
            ['q' => 'How do I set different templates for different audiences?', 'a' => 'Templates can be picked per audience and certificate type. Design one per type (Testamur, Record of Results, Statement of Attainment) and activate it.', 'page' => 'cert_templates.php'],
            ['q' => 'Can I get the settings panel out of the way while I design the certificate?', 'a' => 'Yes. In the template editor toolbar click "Floating properties" to lift the field settings into a movable floating toolbar (drag it by its header), so the design canvas and the live preview fill the screen. Click it again to dock the panel back in the column; your choice is remembered. There is also a one-click "Save & Approve" button, and the live preview sits beside the canvas so you edit on the left and see the finished PDF on the right.', 'page' => 'cert_template_edit.php'],
        ]],
        ['cat' => 'Statements of Attainment', 'icon' => 'document', 'items' => [
            ['q' => 'How do I issue a Statement of Attainment (SoA) to a student?', 'a' => 'Open the SoA Issue page, pick the student, tick the competent units to include, choose the document type, then generate the SoA.', 'page' => 'soa_issue.php'],
            ['q' => 'When do I use a Statement of Attainment instead of a Testamur?', 'a' => 'A Testamur is for a full completed qualification. A Statement of Attainment is for one or more units where the whole qualification is not completed (or for a skill set).', 'page' => 'soa_issue.php'],
            ['q' => 'Can I issue an SoA for part of a qualification or a skill set?', 'a' => 'Yes. In Step 3 choose "Part of Qualification", "Part of Skill Set" or "Standalone" and enter the code and name.', 'page' => 'soa_issue.php'],
            ['q' => 'The SoA shows a compliance warning — can I still issue?', 'a' => 'You can tick "Override compliance warnings" to proceed, but only do this when you are confident the outcome is correct and evidenced.', 'page' => 'soa_issue.php'],
            ['q' => 'Does an SoA cost credits too?', 'a' => 'Yes — like any certificate it costs 5 credits (about A$0.50). You will see the cost before issuing.', 'page' => 'soa_issue.php'],
            ['q' => 'The same unit was showing several times because it was delivered each semester — is that fixed?', 'a' => 'Yes. When a student completed the same unit across more than one semester (each semester is a separate Moodle course copy), the eligible-units list now shows that unit once, keeping the most recent competent completion. A competent result always wins over a non-competent one, so an earlier achievement is never hidden.', 'page' => 'soa_issue.php'],
            ['q' => 'Why does the Unit Code column show a code like TLIX5049 instead of the course/semester name?', 'a' => 'The plugin reads the national unit code from the delivery course — its ID number if set, otherwise the code at the start of the course name (e.g. "TLIX5049 Determine indirect taxes"). That is why semester copies collapse to one unit and the recognised code is printed on the document. If a unit still shows a semester label, rename that Moodle course so it starts with the national code.', 'page' => 'soa_issue.php'],
            ['q' => 'How do I quickly find the right student on the SoA page?', 'a' => 'Use the cascading filters at the top of Step 1 — Parent category, then Sub-category, then Course — or just type in the search box. Choosing a Parent category also auto-fills the Step 3 Qualification Code and Name, because the parent categories are named after the qualification they deliver.', 'page' => 'soa_issue.php'],
        ]],
        ['cat' => 'Credits & billing', 'icon' => 'coin', 'items' => [
            ['q' => 'What are credits used for?', 'a' => 'Credits pay for platform actions: each certificate is 5 credits (about A$0.50), each AI Assistant question is 1 credit, and AI survey analysis is 5 credits.', 'page' => 'ai_usage_report.php'],
            ['q' => 'How do I see my credit balance and usage?', 'a' => 'Your balance is shown bottom-left of the sidebar. The AI Usage Report shows credits used by feature, estimated cost and your remaining balance.', 'page' => 'ai_usage_report.php'],
            ['q' => 'What happens if I run out of credits?', 'a' => 'Actions that cost credits will stop until you top up. Certificate generation, for example, will pause and tell you how many credits you need.', 'page' => 'ai_usage_report.php'],
            ['q' => 'Are automatic certificates free?', 'a' => 'No. Automatic issue is charged the same 5 credits per certificate as manual issue — nothing is issued for free.', 'page' => 'qual_cert_hub.php'],
            ['q' => 'Is emailing a certificate charged?', 'a' => 'No. Emailing an already-issued certificate does not cost credits — only generating the certificate does.', 'page' => 'certificates.php'],
        ]],
        ['cat' => 'Results & completions', 'icon' => 'chart', 'items' => [
            ['q' => 'How do I pull the latest completions from Moodle?', 'a' => 'On Student Results click "Sync from completions". It reads Moodle course completions (including archived and semester copies) and updates your results register.', 'page' => 'qualbuilder_results.php'],
            ['q' => 'Where do I see who has completed what?', 'a' => 'Student Results is the per-qualification register: it shows each student\'s unit outcomes, drawn from Moodle completions, imports and RPL/credit transfer.', 'page' => 'qualbuilder_results.php'],
            ['q' => 'What does "Download unmapped completions" do?', 'a' => 'It gives you a CSV of Moodle courses the sync could not match to a unit — link or rename those courses, then sync again.', 'page' => 'qualbuilder_results.php'],
            ['q' => 'How does the system know a student finished a qualification?', 'a' => 'It checks that every required unit has a completion in a mapped delivery course. When all units are competent, the student is ready for a certificate.', 'page' => 'qualbuilder_results.php'],
            ['q' => 'Can I export the roster for AVETMISS?', 'a' => 'Yes. Use "Export CSV" on Student Results to download the filtered roster with USI, date of birth, state and competency counts.', 'page' => 'qualbuilder_results.php'],
            ['q' => 'How do I filter the Student Results roster by category or course?', 'a' => 'Use the cascading Parent category, Sub-category and Course dropdowns in the filter bar. They are built from the courses students actually hold results in, so choosing a level narrows both the roster and every stat card together. The same cascade is on the Issue SoA page and the Qualification Certificate Hub.', 'page' => 'qualbuilder_results.php'],
        ]],
        ['cat' => 'RPL & credit transfer', 'icon' => 'transfer', 'items' => [
            ['q' => 'How do I record RPL or a credit transfer?', 'a' => 'Open RPL & Credit Transfer, add an application, record the evidence and decision, and it posts the unit outcome automatically (51/52 for RPL, 60 for credit transfer).', 'page' => 'rpl.php'],
            ['q' => 'What is the difference between RPL and credit transfer?', 'a' => 'RPL recognises existing skills/experience assessed against the unit. Credit transfer recognises a unit the student has already completed and been issued elsewhere.', 'page' => 'rpl.php'],
            ['q' => 'Does an RPL outcome count towards a certificate?', 'a' => 'Yes. RPL and credit transfer units count as competent, so a student can complete a qualification through a mix of study, RPL and credit transfer.', 'page' => 'rpl.php'],
            ['q' => 'What evidence should I keep for RPL?', 'a' => 'Record the evidence, the assessor and the decision reason on the RPL record, and upload supporting documents to the student\'s document area.', 'page' => 'rpl.php'],
            ['q' => 'Where do RPL and credit-transfer units show up?', 'a' => 'On the student\'s results grid they appear as competent (RPL or CT), and they flow through to certificates and AVETMISS reporting.', 'page' => 'qualbuilder_results.php'],
        ]],
        ['cat' => 'Trainers & workforce', 'icon' => 'trainer', 'items' => [
            ['q' => 'Where do I record my trainers and assessors?', 'a' => 'Use the Trainer & Assessor Register to record each trainer\'s credentials, vocational competency, industry currency and the units they can deliver.', 'page' => 'trainers.php'],
            ['q' => 'What does the Workforce Management page do?', 'a' => 'It is a planning calculator: enter your delivery mode, trainer and student numbers and assessment load, and it works out ratios, capacity and any unit with no nominated trainer.', 'page' => 'workforce_management.php'],
            ['q' => 'Are the workforce ratios an ASQA rule?', 'a' => 'No. The benchmarks are indicative planning figures you can adjust — ASQA does not set a single mandated ratio; it expects you to demonstrate sufficient staffing for your load.', 'page' => 'workforce_management.php'],
            ['q' => 'How do I record supervision for a "working towards" trainer?', 'a' => 'Use the Supervision Log to record who supervises a trainer who has not yet completed the full TAE credential, and against which units.', 'page' => 'supervision.php'],
            ['q' => 'Where is vocational competency actually verified?', 'a' => 'In the Trainer & Assessor Register — not on the Workforce Management page. Record each trainer\'s competency and currency there.', 'page' => 'trainers.php'],
        ]],
        ['cat' => 'Training strategies (TAS)', 'icon' => 'strategy', 'items' => [
            ['q' => 'How do I create a Training and Assessment Strategy (TAS)?', 'a' => 'Open the TAS Generator and click "Create New TAS". Work through the nine mandated section cards; a completeness percentage shows what is left to fill in.', 'page' => 'tas.php'],
            ['q' => 'What is a TAS?', 'a' => 'A Training and Assessment Strategy documents how you deliver and assess a qualification — the units, delivery, volume of learning, assessment, trainers and industry engagement.', 'page' => 'tas.php'],
            ['q' => 'How do I record industry consultation for a TAS?', 'a' => 'Use the Consultation log to record each engagement, upload evidence, and generate a narrative — the badges show OK, Due, Overdue or No Evidence.', 'page' => 'tas_consultation.php'],
            ['q' => 'Where do nominal hours / volume of learning come from?', 'a' => 'Nominal hours resolve automatically from the plugin\'s reference table and roll up to a qualification total, which pre-fills the TAS volume of learning.', 'page' => 'tas.php'],
            ['q' => 'Can I export a finished TAS?', 'a' => 'Yes. Use the Export action on the TAS to produce the finished document.', 'page' => 'tas.php'],
        ]],
        ['cat' => 'Validation & moderation', 'icon' => 'check', 'items' => [
            ['q' => 'How do I schedule assessment validation?', 'a' => 'Open the Validation Schedule and click "Schedule Validation". Record which units are validated when, and by whom.', 'page' => 'validation.php'],
            ['q' => 'What is assessment validation?', 'a' => 'A quality check where assessors review assessment tools and judgements to confirm they are valid, reliable, sufficient, current and authentic.', 'page' => 'validation.php'],
            ['q' => 'How do I add a validator to the register?', 'a' => 'On the Validators tab use Add Validator (or Edit an existing one) to record each validator\'s name and independence.', 'page' => 'validation.php'],
            ['q' => 'How do I see units that still need validating?', 'a' => 'The Coverage Gaps section highlights units without a recent validation event so you can schedule them.', 'page' => 'validation.php'],
            ['q' => 'Why does independence of validators matter?', 'a' => 'ASQA expects validation to be objective. Recording who validated (and their independence from the delivery/assessment) evidences that.', 'page' => 'validation.php'],
        ]],
        ['cat' => 'Complaints, appeals & improvement', 'icon' => 'feedback', 'items' => [
            ['q' => 'How do I log a complaint?', 'a' => 'Open Complaints & Appeals and click "Lodge New Complaint". Record the details, category, priority and who it is assigned to, then track it to resolution.', 'page' => 'complaints.php'],
            ['q' => 'How do I record an appeal?', 'a' => 'On the same page use the Appeals tab and "Lodge New Appeal". You can record the independent reviewer and whether a result was corrected.', 'page' => 'complaints.php'],
            ['q' => 'What is continuous improvement here?', 'a' => 'It captures improvement actions — often arising from complaints, validation or surveys — and tracks whether each action was effective.', 'page' => 'complaints.php'],
            ['q' => 'How do I gather student and employer feedback?', 'a' => 'Use Surveys to send the learner and employer questionnaires, then read the responses and run AI analysis to surface themes.', 'page' => 'surveys.php'],
            ['q' => 'Where does feedback feed into improvement?', 'a' => 'Survey themes and complaints can be linked to improvement actions, closing the loop for self-assurance.', 'page' => 'qi_report.php'],
        ]],
        ['cat' => 'Governance & risk', 'icon' => 'governance', 'items' => [
            ['q' => 'Where do I record my governing persons and roles?', 'a' => 'Use the Governance page to record governing persons, material changes, roles, meeting minutes and the annual declaration.', 'page' => 'governance.php'],
            ['q' => 'How do I keep a risk register?', 'a' => 'Open Risk Management to record risks, their treatment and reviews, including financial and conflict-of-interest risks.', 'page' => 'risk.php'],
            ['q' => 'How do I record a third-party arrangement?', 'a' => 'Use Third-Party Arrangements to record each partner, the services they provide and any notification to ASQA. (There is no fixed 30-day advance-notice rule.)', 'page' => 'thirdparty.php'],
            ['q' => 'Where do I record insurance and fee protection?', 'a' => 'Insurance policies go on the Insurance page; student fee protection arrangements go on the Fee Protection page.', 'page' => 'insurance.php'],
            ['q' => 'What is "self-assurance"?', 'a' => 'The 2025 Standards expect you to continuously monitor your own performance and fix issues before an audit. Compliance Health, the Validation Schedule and Alerts support this.', 'page' => 'compliance_health.php'],
        ]],
        ['cat' => 'State funding', 'icon' => 'dollar', 'items' => [
            ['q' => 'How do I set up state funding fields?', 'a' => 'Open Plugin Settings → State Funding and complete only the states where you hold a funded contract: your STA identifier, contract references and default funding-source code.', 'page' => 'plugin_settings.php'],
            ['q' => 'What is the "funding source" field?', 'a' => 'It tells AVETMISS how the enrolment was funded. There is a national code and a state-specific code identifying the exact program/contract.', 'page' => 'plugin_settings.php'],
            ['q' => 'Are the funding-source codes in the plugin the official ones?', 'a' => 'Treat them as a starting point only. Each State Training Authority defines and updates its own codes (for example Queensland replaced its old programs with Career Start and Career Boost in 2025) — always confirm against your STA\'s current spec.', 'page' => 'plugin_settings.php'],
            ['q' => 'Does the plugin submit my funded claim?', 'a' => 'No. It prepares your AVETMISS data; you submit through your State Training Authority\'s own portal (for example STS Online, SVTS, the QLD Partner Portal, or WA\'s RAPT/TAMS).', 'page' => 'natexport.php'],
            ['q' => 'Where can I learn more about AVETMISS funding fields?', 'a' => 'The NCVER RTO Hub is the home of AVETMISS reporting resources and the collection specification that defines the funding fields. The State Funding help pill links there.', 'page' => 'plugin_settings.php'],
        ]],
        ['cat' => 'AI assistant & support', 'icon' => 'sparkle', 'items' => [
            ['q' => 'What is the AI Assistant?', 'a' => 'A built-in expert you can chat with (bottom-right of any plugin page). It knows this software and the ASQA 2025 Standards, and answers in plain English with links to the right page.', 'page' => ''],
            ['q' => 'How much does the AI Assistant cost?', 'a' => 'One credit per question. You are told the cost up front, and you can turn the assistant off in Plugin Settings if you prefer.', 'page' => 'plugin_settings.php'],
            ['q' => 'I cannot see the AI Assistant — why?', 'a' => 'It appears only on plugin pages, for staff, when the "Show AI Assistant" setting is on and your Platform API details are set. Check those, then reload.', 'page' => 'plugin_settings.php'],
            ['q' => 'How do I check whether the plugin upgraded correctly?', 'a' => 'The installed version is shown under "RTO Compliance" in the sidebar (e.g. "ASQA 2025 · v6.x"). If it shows an old number, install the latest ZIP from Plugin management.', 'page' => 'index.php'],
            ['q' => 'Where do I find step-by-step help for each area?', 'a' => 'This FAQ, the Support page (detailed how-to for every module) and the Help card at the top of each page. For anything else, ask the AI Assistant.', 'page' => 'support.php'],
        ]],
    ];
}
