<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify it under the terms of
// the GNU General Public License as published by the Free Software Foundation, either
// version 3 of the License, or (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY.
// See the GNU General Public License for more details. You should have received a copy of
// the GNU General Public License along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Support-centre Q&A data (single source of truth). support.php renders it, and the AI
 * assistant knowledge base (local_rtocompliance_assistant_kb) ingests it, so both stay in
 * sync automatically. Extracted from support.php in v6.2.76.
 *
 * @package   local_rtocompliance
 * @copyright 2026 LMS Labs
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * @return array list of ['category'=>string,'icon'=>string,'faqs'=>[['question'=>..,'answer'=>..],...]]
 */
function local_rtocompliance_support_faq_data(): array {
    return [

    // ── GETTING STARTED ────────────────────────────────────────────────────
    [
        'category' => 'Getting Started',
        'icon'     => 'layout-dashboard',
        'faqs'     => [
            [
                'question' => 'What should I do first after installing the plugin?',
                'answer'   => 'Start with four quick setup steps: (1) Go to Site Administration &rarr; AI RTO Compliance &rarr; Settings and enter your RTO name, RTO number, and AVETMISS reporting identifier. (2) Open Qualification Builder and add your qualifications — fetch their units from training.gov.au. (3) Add your trainers in the Trainer Register and record their TAE qualifications. (4) Add your students in Student Records. Once those four are complete, your compliance dashboard will start showing real data.',
            ],
            [
                'question' => 'What do the coloured tiles on the compliance dashboard mean?',
                'answer'   => 'Each tile represents one area of the Standards for RTOs 2025. Red tiles require urgent attention — something is overdue or non-compliant. Amber tiles are approaching a deadline. Green tiles are compliant. Grey tiles have no records yet (which is itself a compliance risk if that area is part of your scope). Click any tile to jump straight to that module.',
            ],
            [
                'question' => 'Does the plugin work with any version of Moodle?',
                'answer'   => 'The plugin is developed and tested against Moodle 4.1 LTS and later. It will install on Moodle 3.11 but some hook-based features (such as the automatic header/footer injections) require Moodle 4.3+. If you are on an older Moodle, those features degrade gracefully — they simply do not appear rather than causing errors.',
            ],
            [
                'question' => 'Where do I enter my RTO number and ABN?',
                'answer'   => 'Go to Site Administration &rarr; Plugins &rarr; Local plugins &rarr; AI RTO Compliance &rarr; Settings. The General section at the top has fields for your RTO name, RTO number (your ASQA registration number), ABN, AVETMISS reporting identifier, and contact details. These values are used on certificates, in NAT file headers, and throughout the plugin.',
            ],
            [
                'question' => 'Can more than one administrator use the plugin at the same time?',
                'answer'   => 'Yes — the plugin is fully multi-user. Any Moodle user with the site administrator role or the custom <code>local/rtocompliance:manage</code> capability can access and edit records simultaneously. All changes are written to the Audit Log with the user\'s name and timestamp, so you always know who changed what.',
            ],
            [
                'question' => 'I just installed the plugin and the dashboard shows no data — is something wrong?',
                'answer'   => 'No — this is normal for a fresh install. The dashboard tiles only show counts from records you have created. Start by adding at least one qualification in Qualification Builder, one trainer in the Trainer Register, and one student in Student Records. After that, the relevant dashboard tiles will update within a few minutes (some counts are cached by Moodle for up to 10 minutes).',
            ],
        ],
    ],

    // ── QA1 – TRAINING & ASSESSMENT ────────────────────────────────────────
    [
        'category' => 'QA1 – Training & Assessment',
        'icon'     => 'clipboard-list',
        'faqs'     => [
            [
                'question' => 'How many sections does a TAS need to cover?',
                'answer'   => 'The TAS Generator has 9 sections, which cover everything ASQA expects to see in a Training and Assessment Strategy: qualification details, target cohort, industry consultation evidence, volume of learning, delivery modes, resources and facilities, trainer credentials mapped to units, assessment design, reasonable adjustments, LLN considerations, third-party delivery, transition arrangements, and continuous improvement. All 9 sections must be completed before the system allows you to export.',
            ],
            [
                'question' => 'How often do I need to validate my assessments?',
                'answer'   => 'Standard QA1.5 requires assessment tools and practices to be validated within a 5-year cycle, with high-risk products prioritised for more frequent review. The Validation Schedule uses a risk-based approach — products flagged as high risk are recommended for annual validation. The compliance dashboard shows any overdue validations so nothing slips past the 5-year mark.',
            ],
            [
                'question' => 'What is the difference between RPL and Credit Transfer?',
                'answer'   => 'Recognition of Prior Learning (RPL) involves an assessor evaluating a student\'s existing skills and knowledge against unit competency requirements — it requires an assessment process and a documented evidence portfolio. Credit Transfer (CT) is simpler: it recognises a completed unit from another RTO or institution where the unit code is identical or equivalent, with no reassessment required. Both are recorded in the RPL & Credit Transfer register and appear as AVETMISS outcome codes 51/52 (RPL) and 60 (CT).',
            ],
            [
                'question' => 'What does the RPL & Credit Transfer record now capture for a compliant decision?',
                'answer'   => 'Each record captures the assessor chosen from your registered trainers (with a live check on their TAE currency, Standard 1.5); the RPL evidence files and, for credit transfer, the issuing RTO\'s source certificate plus USI-transcript verification; an evidence-to-criteria matrix that maps each item of evidence to the unit requirement it satisfies with an assessor judgement (rules of evidence, Standard 1.2); a superseded→current unit mapping with its TGA equivalence (a "not equivalent" mapping is flagged as needing gap assessment); the decision and written justification; and whether the outcome was communicated to the student, when and how (Standard 1.6). An approved decision posts the competent outcome (RPL 51 / CT 60) into Student Results, certificates and NAT.',
            ],
            [
                'question' => 'Where do nominal hours come from, and where are they used?',
                'answer'   => 'training.gov.au does not publish nominal hours, so the plugin keeps its own authoritative reference table. Load the NCVER nationally-agreed dataset (and any state overrides) via Import Nominal Hours. The Qualification Builder then resolves each unit\'s hours and rolls them up to a qualification total — flagging any unit still missing a value — and that total pre-fills the TAS Total Nominal Hours / volume of learning and defaults the AVETMISS NAT00120 scheduled hours (0 for RPL/CT, which have no delivery).',
            ],
            [
                'question' => 'Do I need to register every site where I deliver training?',
                'answer'   => 'Yes — Standard QA1.8 requires you to ensure all training and assessment environments are safe and appropriate. ASQA will ask for evidence of this during an audit. Use the Delivery Locations register to record every physical campus, workplace delivery site, and online environment used for training. Include the WHS compliance status and last inspection date for each location.',
            ],
            [
                'question' => 'What happens when a qualification on my scope gets superseded?',
                'answer'   => 'When training.gov.au marks a qualification as superseded or deleted, you have a teach-out period (typically 12 months) during which existing students can complete, but you cannot enrol new students. Open Training Product Transitions and create a Transition Plan for the affected qualification. You can set the teach-out end date and optionally link the Moodle course to automatically close self-enrolment on that date. All current students need a documented transition strategy recorded in the plan.',
            ],
            [
                'question' => 'The training.gov.au fetch is not returning any units — what do I check?',
                'answer'   => 'First, check that the qualification code is correct (e.g. ABC12345 — no spaces, correct version suffix). Second, check your server can reach <code>training.gov.au</code> on port 443 — some hosting providers block outbound SOAP calls. Third, try purging Moodle caches (Site Administration &rarr; Development &rarr; Purge all caches) and retrying. If the problem persists, the training.gov.au SOAP API may be experiencing downtime — check their status page and try again later.',
            ],
            [
                'question' => 'What do the competency outcome codes C, NYC, RPL, and CT mean?',
                'answer'   => 'C = Competent (AVETMISS 20) — the student has demonstrated competency in the unit. NYC = Not Yet Competent (AVETMISS 30) — the student has not yet met the standard. RPL = Recognition of Prior Learning granted (AVETMISS 51). CT = Credit Transfer (AVETMISS 60). A student needs C, RPL, or CT on every unit in a qualification before a Testamur can be issued. In Student Results, units showing these codes are shaded accordingly so you can see at a glance who is ready for certification.',
            ],
            [
                'question' => 'Can I run packaging rules validation before issuing a certificate?',
                'answer'   => 'Yes — in Qualification Builder, click the "Validate Packaging" button on any qualification. The packagingrules validator checks that the unit selection meets the minimum core count, minimum and maximum elective counts, and any prerequisite rules specified in the training package. A green pass means the combination is valid for certification; a red fail shows exactly which rule was not met.',
            ],
            [
                'question' => 'What are teacher-cohort variant courses and why do I need them?',
                'answer'   => 'Many RTOs run the same unit across multiple Moodle courses at the same time — for example ABC12345 delivered separately by three different trainers (a CD stream, an EL stream, and an ND stream). Without variant course support, only students in the single "primary" linked course get their AVETMISS enrolment record created and their certificate issued automatically. Students in the other streams were invisible to the system. Variant courses solve this: on each unit row in the Qualification Builder you will see small chips for every Moodle course in the semester that shares that unit code — e.g. <strong>[✓ EL] [CD ×] [ND ×] [+ add variant…]</strong>. All chipped courses are watched by the reconciler, so students in any stream get their records and certs automatically.',
            ],
            [
                'question' => 'How do variant course chips work in the Qualification Builder?',
                'answer'   => 'When you select a semester in the Qualification Builder, clicking "Map All Courses" (or changing the semester) auto-detects all Moodle courses whose short name contains the unit code. The primary course gets the green ✓ badge; all others appear as grey chips. Click the × on a chip to remove a course you do not want watched (e.g. an old test course). Use the [+ add variant…] dropdown to manually add a course that was not auto-detected. If you promote a variant to the primary course (by changing the primary dropdown), that course is automatically removed from the chips. Changes only take effect when you click "Save".',
            ],
            [
                'question' => 'How does automatic certificate issuance (autocert) work?',
                'answer'   => 'Every time a Moodle course completion event fires, the enrolment reconciler checks whether the student now has Competent outcomes (C, RPL, or CT) on every unit in the qualification — across the primary linked course and all variant courses. If all units are complete, the system automatically generates and saves a Testamur to the certificate register, sets the student\'s programme outcome to Complete, and records the AVETMISS result code. No manual action is required. The 30-day issuance clock in Compliance Requirements Clause 9(2) begins from the date the final course completion fires.',
            ],
            [
                'question' => 'Can I have two Qualification Builder records for the same qualification code?',
                'answer'   => 'Yes — this is intentional and supported. Use the optional <em>Stream / Variant Name</em> field on the qualification record to distinguish them (e.g. "Evening Intake 26S1", "CD Stream"). The stream label appears as a small badge in the QB list view so staff can tell them apart at a glance. Each record has its own unit–course mappings and variant chips, allowing completely different delivery configurations under the same TGA qualification code.',
            ],
        ],
    ],

    // ── QA2 – STUDENT SUPPORT ──────────────────────────────────────────────
    [
        'category' => 'QA2 – Student Support & Enrolment',
        'icon'     => 'users',
        'faqs'     => [
            [
                'question' => 'What does Standard 2.1 require me to show prospective students?',
                'answer'   => 'Standard QA2.1 requires you to provide accurate, accessible, and up-to-date information about your training products, services, fees, refund policy, complaints process, and the certificate each course leads to — before a student enrols. The Marketing Information register helps you document that your website, brochures, and social media content have been reviewed for accuracy. Keep a record of what was reviewed, who approved it, and when — ASQA will ask for this.',
            ],
            [
                'question' => 'How do I check a student was properly pre-enrolled?',
                'answer'   => 'Open the student from Student Records — the profile shows a pre-enrolment readiness card with four gates: suitability assessed (a completed suitability review with a genuine decision), student declaration signed, USI verified, and pre-enrolment information provided. Each gate shows met, warning, or not-met with dates, and a "N of 4 met" header. Compliance Health (Quality Area 2) adds an aggregate metric — "Pre-enrolment suitability not evidenced" — counting students who have recorded results but no completed suitability review, linked to the bulk suitability sender. This is a read-only audit signal; it never creates or blocks a Moodle enrolment.',
            ],
            [
                'question' => 'How do I add a new student?',
                'answer'   => 'Navigate to Student Records in the QA2 sidebar group and click "Add Student". Enter the student\'s full legal name, date of birth, contact details, and USI. For nationally recognised training, complete all AVETMISS fields — the system highlights the mandatory fields in red and will not allow the record to be saved with missing mandatory data. Once saved, the student appears in Student Results for any qualification linked to their course enrolment.',
            ],
            [
                'question' => 'What AVETMISS fields are mandatory for every student?',
                'answer'   => 'For nationally recognised training (NRT), NCVER requires 11 fields as mandatory: given name, family name, date of birth, gender, residential address (suburb, state, postcode), country of birth, indigenous status, language spoken at home, highest school level completed, labour force status, and disability/impairment indicator. The student profile completeness checker flags any of these that are missing so you can follow up before your AVETMISS submission deadline.',
            ],
            [
                'question' => 'What is a USI and do all students need one?',
                'answer'   => 'A Unique Student Identifier (USI) is a reference number that creates an online record of an individual\'s nationally recognised VET training. Every student enrolled in NRT must provide a USI before a certificate can be issued (Clause 12 of the USI Act). International students and some other exempt groups may be exempt. Use the USI Verification button on a student\'s profile to confirm their USI against the national registry in real time.',
            ],
            [
                'question' => 'What is the Student Suitability Check for?',
                'answer'   => 'Standard QA2.2 requires RTOs to assess whether a prospective student has the LLN (Language, Literacy and Numeracy) skills and meets the entry requirements for the qualification before they enrol. The Student Suitability Check is a 4-stage digital form sent by email to the student. It collects evidence of entry requirements (Stage 1), shows the LLN assessment result (Stage 2), displays the system\'s suitability decision with plain-language advice (Stage 3), and captures the student\'s signed declaration (Stage 4). This creates an auditable record that your admissions process is fair and transparent.',
            ],
            [
                'question' => 'What is the Student Support System page for — is it per student?',
                'answer'   => 'No — the Student Support page is the organisation-level configuration. Here you set which support services your RTO offers (e.g. language support, counselling, financial assistance, flexible scheduling), which types of reasonable adjustments are available, and your diversity and wellbeing policies. These settings become the options that trainers choose from when they complete a per-student support plan via the Trainer Input page. Think of it as the master menu — trainers select from it for each student.',
            ],
            [
                'question' => 'How do I issue a certificate?',
                'answer'   => 'Navigate to Student Results and find a student who shows 100% completion across all required units. Click the "Issue Certificate" button. Select the certificate type (Testamur for a full qualification, Statement of Attainment for partial, Record of Results, or Certificate of Completion for non-accredited). Select the audience variant if applicable (e.g. apprentice, VET-FEE, international). The PDF is generated using your active custom template and saved to the 30-year certificate register automatically. You can then download it or email it directly to the student.',
            ],
            [
                'question' => 'What is the 30-day rule for issuing certificates?',
                'answer'   => 'Compliance Requirements Clause 9(2) requires RTOs to issue AQF qualifications and statements of attainment within 30 calendar days of the student meeting all requirements for the certification. The Certificates module tracks the gap between the date of completion and the date of issue. Any certificate that took more than 30 days to issue is flagged as "Issued Late" on the dashboard — this is an ASQA compliance breach if it occurs regularly.',
            ],
            [
                'question' => 'What are the four certificate types and when do I use each one?',
                'answer'   => '<strong>Testamur</strong> — issued when a student completes all required units in a nationally recognised qualification. This is the actual qualification certificate. <strong>Statement of Attainment (SOA)</strong> — issued when a student completes one or more nationally recognised units but not a full qualification. <strong>Record of Results (ROR)</strong> — a supplementary document listing all units attempted and their outcomes; often issued alongside a Testamur. <strong>Certificate of Completion</strong> — for non-accredited (non-NRT) training where no AQF qualification is awarded.',
            ],
            [
                'question' => 'What mandatory elements must appear on an AQF certificate?',
                'answer'   => 'Per the AQF Certification Documentation specification and the ASQA Sample Forms fact sheet, a Testamur must include: the RTO\'s registered legal name and RTO number; the AQF certification logo; the NRT logo; the student\'s full legal name; the qualification code and full title exactly as it appears on training.gov.au; the words "This qualification is recognised within the Australian Qualifications Framework"; the issue date; and the authorised signatory\'s name and title. The Certificate Templates validator will flag any missing mandatory element before allowing a template to be approved.',
            ],
            [
                'question' => 'Can I have a different certificate design for each certificate type?',
                'answer'   => 'Yes — the Certificate Templates module supports separate active templates for each of the four certificate types (Testamur, SOA, Record of Results, Certificate of Completion) and up to nine audience variants (general, apprentice, VET-FEE, international, traineeship, school-based, recognition, workplace, fee-for-service). Each combination can have its own approved template. If no custom template is active for a particular type/audience combination, the system falls back to the built-in ASQA-compliant default.',
            ],
            [
                'question' => 'How do I preview what my certificate will look like before issuing real ones?',
                'answer'   => 'Use the Test Certificate Generator (QA2 sidebar group &rarr; Test Certificate). Select the certificate type, choose the audience variant, and click "Generate Test PDF". The test uses the exact same rendering pipeline as a live certificate — your active custom template, your uploaded logos, and real field positions — but uses a synthetic student name and does not save anything. Use this every time you update your template design to confirm it looks correct before it goes to real students.',
            ],
            [
                'question' => 'What NAT files do I need to generate for NCVER?',
                'answer'   => 'For a standard AVETMISS collection you need 10 NAT files: NAT00010 (training organisation), NAT00020 (training organisation delivery locations), NAT00030 (qualification/course), NAT00060 (subject/unit), NAT00080 (client), NAT00085 (disability), NAT00090 (prior educational achievement), NAT00100 (enrolment), NAT00120 (subject enrolment), and NAT00130 (outcome). The NAT Export module generates all 10 as a ZIP file ready for submission to your State Training Authority or NCVER directly.',
            ],
            [
                'question' => 'Can I import student data from my previous student management system?',
                'answer'   => 'Yes — use Data Import. It accepts two formats: a NAT file set (if your old system can export AVETMISS-compliant NAT files) or the plugin\'s own CSV import template. The importer validates every row before writing to the database — it checks for duplicate USIs, invalid AVETMISS codes, missing mandatory fields, and date format errors. A preview screen shows exactly how many records will be created, updated, or skipped before you confirm the import.',
            ],
            [
                'question' => 'How do I send a Quality Indicator survey to students?',
                'answer'   => 'Open Quality Indicator Surveys from the QA2 sidebar group and click "Send Survey". Select either Learner Engagement or Employer Satisfaction as the survey type, then choose the recipients (individual students, all students in a course, or all completions within a date range). Students receive an email with a unique link to their survey — no Moodle login is required to respond. Results appear in the Reports tab as they come in. Export to CSV for your annual NCVER QI submission.',
            ],
            [
                'question' => 'How do I record a formal complaint?',
                'answer'   => 'Open Complaints & Appeals and click "Add Complaint" on the Complaints tab. Complete the complaint form — you can mark it as anonymous if the complainant has requested confidentiality. Select a category (academic, administrative, fees, discrimination, etc.), assign an investigator, and set a resolution target date. ASQA expects complaints to be acknowledged within 5 business days and resolved within 60 days. Document all investigation notes and the final outcome in the system. If the issue is systemic, use the Improvement tab to create a linked improvement action.',
            ],
            [
                'question' => 'What is the difference between a complaint and an appeal?',
                'answer'   => 'A complaint is about any aspect of your RTO\'s services, products, staff, or facilities — it can come from a student, employer, or member of the public. An appeal is specifically a formal challenge to an assessment decision — a student who believes they were assessed unfairly. Both are managed through the Complaints & Appeals module but on separate tabs, and both feed into your continuous improvement register. ASQA requires written policies covering both, separate processes, and records of all matters received and resolved.',
            ],
        ],
    ],

    // ── QA3 – VET WORKFORCE ────────────────────────────────────────────────
    [
        'category' => 'QA3 – VET Workforce',
        'icon'     => 'user-check',
        'faqs'     => [
            [
                'question' => 'What TAE qualifications must trainers hold?',
                'answer'   => 'Under Standard QA3.2, trainers who design and deliver training must hold at minimum a TAE40116 (or TAE40122) Certificate IV in Training and Assessment, or a higher-level qualification in adult education. Trainers who only assess (roles 2A–2C) must hold both a relevant Skill Set from the TAE Training Package and the TAESS00001 or TAESS00011 assessor skill set, or a TAE40116/40122 qualification. There is no exemption — every trainer and assessor in your register must meet these requirements, or be currently working towards them under supervision.',
            ],
            [
                'question' => 'What are the trainer role classifications (1A through 3B)?',
                'answer'   => 'The plugin uses ASQA\'s role matrix: <strong>1A</strong> — holds TAE Cert IV + vocational competency + industry currency (can train and assess). <strong>1B</strong> — holds higher adult education qual + vocational competency + currency (can train and assess). <strong>1C</strong> — holds TAE Cert IV but vocational competency is current practice only (must be supervised by 1A/1B for assessment). <strong>1D</strong> — significant industry experience, no TAE yet (can train under supervision, cannot assess). <strong>1E</strong> — working towards TAE (must be supervised for all delivery). <strong>2A/2B/2C</strong> — assessors with varying levels of TAE completion (cannot deliver training, only assess). <strong>3A/3B</strong> — validators (review assessment tools and practices, typically 1A or 1B holders with additional validation experience).',
            ],
            [
                'question' => 'What is vocational competency and how do I record it?',
                'answer'   => 'Vocational competency means the trainer has relevant skills and knowledge in the vocation they are training — they are not just qualified to train, they also know their subject matter. ASQA accepts several types of evidence: a formal qualification in the relevant field, demonstrated industry experience (usually 3+ years), participation in professional development activities, or a combination. In the Trainer Register, click on a trainer and use the Vocational Competency section to select the evidence types they hold and record the details. These records must be kept current.',
            ],
            [
                'question' => 'What is industry currency and how often does it need updating?',
                'answer'   => 'Industry currency means the trainer has maintained up-to-date knowledge of current industry practices — they are not teaching outdated methods. ASQA expects trainers to have engaged with their industry within the last 12–24 months, usually through workplace visits, professional memberships, short courses, or attending industry events. Use the Industry Currency tab on a trainer\'s profile to record each currency activity with its date. The dashboard flags trainers whose last recorded currency activity is more than 12 months old.',
            ],
            [
                'question' => 'How often should trainer credentials be reviewed?',
                'answer'   => 'Best practice under Standard QA3.2 is an annual credential review for every trainer and assessor. During each review, confirm that their TAE qualification is still current (some units have expiry requirements), that vocational competency evidence is documented and recent, and that industry currency activities have been recorded within the last 12 months. Set the "Next Review Date" on each trainer\'s profile — overdue reviews are flagged in red on the compliance dashboard and in the Trainers & Assessors module.',
            ],
            [
                'question' => 'What is the Supervision Log for?',
                'answer'   => 'When a trainer is in roles 1C, 1D, 1E, 2B, or 2C — meaning they are still working towards a full TAE qualification or have limited assessment rights — the RTO must supervise their delivery and/or assessment and document that supervision. The Supervision Log records each session: who supervised whom, the date, how long the session ran, what activities were covered, and the supervisor\'s sign-off. These records are the evidence ASQA needs to confirm you are not leaving unqualified trainers to assess students unsupervised.',
            ],
            [
                'question' => 'What does ASQA look for in Standard QA3.2 during an audit?',
                'answer'   => 'ASQA auditors will check: (1) that every trainer and assessor in your Trainer Register holds the required TAE qualification for their role classification; (2) that vocational competency evidence exists for each trainer, mapped to the qualifications they deliver; (3) that industry currency activities are documented and current; (4) that any trainer working under supervision has a Supervision Log; and (5) that your VET Workforce Management register shows you have sufficient staffing to meet your scope. The VET Workforce Management page in this plugin is designed to generate the evidence for item 5.',
            ],
        ],
    ],

    // ── QA4 – GOVERNANCE ───────────────────────────────────────────────────
    [
        'category' => 'QA4 – Governance & Quality',
        'icon'     => 'building',
        'faqs'     => [
            [
                'question' => 'What is the Annual Declaration of Compliance?',
                'answer'   => 'The Annual Declaration of Compliance (ADC) is a statutory declaration that the CEO or equivalent of your RTO must submit to ASQA every year — typically due by 30 June. It declares that your RTO has complied with the Standards for RTOs 2025 throughout the preceding year. Use the Governance module to record the ADC lodgement date and ASQA reference number each year. ASQA will check this record during any audit.',
            ],
            [
                'question' => 'Who counts as a "governing person" and do I need to register them all?',
                'answer'   => 'A governing person is any individual with significant influence over your RTO\'s operations — directors, trustees, partners, and senior managers (CEO, CFO, RTO Manager). Standard QA4.1 requires all governing persons to be "fit and proper" (no relevant convictions, not a disqualified person under the NVETR Act). Use the Governing Persons register in the Governance module to record each person\'s name, role, appointment date, and fit-and-proper declaration status.',
            ],
            [
                'question' => 'What is a material change and when must I notify ASQA?',
                'answer'   => 'A material change is any significant change to your RTO\'s structure, operations, or compliance that ASQA needs to know about — for example: a change in ownership or controlling entity, a change in CEO or RTO Manager, adding or removing a principal delivery location, a significant change in scope, or a legal/financial issue affecting your ability to operate. The NVETR Act requires notification to ASQA within 90 days (for most changes) or immediately for urgent matters. Record all material changes in the Governance module\'s Material Changes tab so you have evidence of timely notification.',
            ],
            [
                'question' => 'How do I record and rate a risk?',
                'answer'   => 'Open Risk Management and click "Add Risk". Give the risk a clear title (e.g. "Key trainer leaving with no succession plan"), select the category (strategic, operational, financial, or compliance), and write a brief description. Rate the likelihood (1–5 scale) and the consequence (1–5 scale) — the system multiplies these to calculate an overall risk rating. Write a treatment plan describing what you will do to reduce the risk, assign a risk owner, and set a review date. High-rated risks (rating of 15+) are flagged in red on the compliance dashboard.',
            ],
            [
                'question' => 'What continuous improvement evidence does ASQA expect?',
                'answer'   => 'Standard QA4.4 requires your RTO to have a systematic continuous improvement system. ASQA auditors look for: (1) Quality Indicator data collected from learners and employers and submitted to NCVER; (2) improvement actions that were triggered by complaints, appeals, survey feedback, or self-assurance activities; (3) evidence that those actions were actually implemented and their effectiveness reviewed; and (4) the cycle repeating each year. The Complaints & Improvement module, the QI Surveys module, and the ASQA Practice Guides self-assurance tool together provide this evidence trail.',
            ],
        ],
    ],

    // ── COMPLIANCE STANDARDS ───────────────────────────────────────────────
    [
        'category' => 'Compliance Standards',
        'icon'     => 'shield',
        'faqs'     => [
            [
                'question' => 'What counts as a third-party arrangement?',
                'answer'   => 'Any agreement where another organisation delivers training or assessment on your RTO\'s behalf — or manages your students — counts as a third-party arrangement under Compliance Requirements, Division 3 Clause 17. This includes subcontractors who deliver in workplaces, auspiced arrangements, licensed trainer networks, and online content providers. Every such arrangement must be covered by a written agreement, and your RTO must monitor the quality of the delivery. The Third-Party Arrangements register is where you record these agreements and document your quality oversight activities.',
            ],
            [
                'question' => 'What is the $1,500 prepaid fee rule?',
                'answer'   => 'Compliance Requirements, Division 3 Clause 18 of the Standards for RTOs 2025 prohibits RTOs from collecting more than $1,500 in prepaid fees from any individual student before the commencement of the training they have paid for. This protects students from losing large sums if the RTO closes. You can collect the full course fee in advance — but only up to $1,500 before training starts, with the remainder collected progressively as training proceeds. The Fee Protection module tracks prepaid balances so you never accidentally breach the $1,500 threshold.',
            ],
            [
                'question' => 'Can I collect more than $1,500 if I have a fee protection mechanism in place?',
                'answer'   => 'Yes — there is an exemption if you hold an approved fee protection mechanism. This typically means having an approved trust account, bank guarantee, or professional indemnity arrangement specifically for student fee protection. If your RTO is approved for a fee protection mechanism, record this in the Fee Protection module settings. The module will then allow you to record prepaid fees above $1,500 as protected rather than flagging them as a compliance breach.',
            ],
            [
                'question' => 'What insurance must an RTO hold?',
                'answer'   => 'Compliance Standard 8 requires RTOs to hold insurance adequate to their operations. At minimum this means: <strong>Public Liability</strong> insurance (typically at least $20 million cover) to protect against claims from students, visitors, and the public; <strong>Professional Indemnity</strong> insurance to protect against claims arising from your training and assessment services. Depending on your structure, you may also need Workers Compensation and Building/Contents insurance. The Insurance Register tracks all policies, coverage amounts, and expiry dates, and alerts you 60 days before any policy expires.',
            ],
        ],
    ],

    // ── AVETMISS & REPORTING ───────────────────────────────────────────────
    [
        'category' => 'AVETMISS & Reporting',
        'icon'     => 'database',
        'faqs'     => [
            [
                'question' => 'When do I need to submit AVETMISS data to NCVER?',
                'answer'   => 'Submission deadlines depend on your funding type. For government-funded training, most State Training Authorities require quarterly or monthly data submissions. For fee-for-service (non-funded) activity, NCVER\'s Total VET Activity (TVA) collection is typically due by 28 February each year, covering the previous calendar year. Check with your State Training Authority for your specific deadline — it varies by state. Start your AVETMISS export in this plugin at least 2 weeks before your deadline so you have time to resolve any validation errors.',
            ],
            [
                'question' => 'What AVETMISS outcome codes does the plugin use?',
                'answer'   => 'The plugin uses only the NCVER-approved codes from AVETMISS VET Provider Collection Specifications Release 8.0: <strong>20</strong> — Competent (C); <strong>30</strong> — Not Yet Competent (NYC); <strong>40</strong> — Withdrawn; <strong>51</strong> — RPL Granted; <strong>52</strong> — RPL Not Granted; <strong>60</strong> — Credit Transfer; <strong>70</strong> — Continuing Enrolment. Non-standard codes (65, 53, 54, 66, 90) that appeared in older versions have been removed.',
            ],
            [
                'question' => 'My NAT export has validation errors — what do I do?',
                'answer'   => 'The most common causes are: (1) students with missing AVETMISS fields — check the Student Records completeness indicator and fill in any gaps; (2) enrolments still coded as "70 Continuing" from a previous year — these should be updated to their final outcome (20/30/40/51/52/60) before export; (3) an invalid qualification code — confirm the code still exists on training.gov.au as an active qualification; (4) a USI that failed verification — the student may have provided an incorrect USI. After fixing issues, purge Moodle caches and re-run the export.',
            ],
            [
                'question' => 'What is the difference between the government-funded and fee-for-service collections?',
                'answer'   => 'The government-funded collection includes all training subsidised by your State Training Authority — it must include client-level data (NAT00080, NAT00085, NAT00090) for every enrolled student. The fee-for-service (Total VET Activity) collection covers all other NRT training your RTO delivers. Some fields that are optional in fee-for-service (like disability information) are mandatory in government-funded. The NAT Export module lets you select which collection type you are generating, and adjusts the validation rules accordingly.',
            ],
            [
                'question' => 'Can I resubmit AVETMISS data after I have already submitted?',
                'answer'   => 'Yes — you can regenerate the NAT files at any time and submit a revised collection. Most collection portals (including NCVER\'s direct submission portal and State Training Authority portals) support replacement submissions. Simply generate a new set of NAT files, fix the errors, and submit the corrected files. Keep a copy of both the original and corrected submission in your records.',
            ],
        ],
    ],

    // ── CERTIFICATES & TEMPLATES ───────────────────────────────────────────
    [
        'category' => 'Certificates & Templates',
        'icon'     => 'award',
        'faqs'     => [
            [
                'question' => 'How long must certificate records be kept?',
                'answer'   => 'AQF certification documentation (certificates and statements of attainment) must be retained for 30 years from the date of issue. Student enrolment records and training activity data must be retained for a minimum of 7 years. The Certificates register in this plugin is designed to be your permanent 30-year record. If your RTO ceases to operate, you are required to transfer your certificate records to NCVER\'s National VET Data collection.',
            ],
            [
                'question' => 'What is USI Clause 12 and how does the plugin enforce it?',
                'answer'   => 'Clause 12 of the Student Identifiers Act 2014 states that an RTO must not issue a VET qualification or statement of attainment to a student unless their USI has been verified. The plugin now enforces this as a hard block: a certificate cannot be issued until the student\'s USI has been VERIFIED against the national Registry (not merely entered). Students who have completed results but hold an unverified USI are listed on Compliance Health as certificates you can\'t yet issue — verify the USI there or from the student\'s profile, and the block clears. Knowingly issuing a certificate without a verified USI is a statutory breach, which is why issuance is prevented rather than merely warned.',
            ],
            [
                'question' => 'How do I fix the NRT logo on my certificates?',
                'answer'   => 'The plugin includes the official ASQA-issued NRT mark (red/green chevron triangle with Fritz Quadrata-style lettering) as the default NRT logo. If your certificates are showing a blank or incorrect NRT mark, it may be because a custom template is active that references an old or missing logo file. Open Certificate Templates, edit the active template, and use the Branding panel to re-upload or re-select the NRT logo. Use the Test Certificate Generator to confirm it renders correctly before reissuing.',
            ],
            [
                'question' => 'My certificate PDF is blank or not rendering — what do I check?',
                'answer'   => 'Try the following in order: (1) Open Test Certificate Generator and test with the same certificate type and audience — if the test is also blank, the issue is with the template, not the student data. (2) Check that the active template for that type/audience has all required fields mapped. (3) Check that the template\'s logo image files still exist (go to Certificate Templates and re-upload if missing). (4) If the test certificate renders correctly but the live one does not, check that the student record has a full legal name and completion date. (5) If using the legacy renderer fallback, check your server\'s TCPDF installation. Contact support if none of these resolve the issue.',
            ],
            [
                'question' => 'Can I email a certificate directly to a student from the plugin?',
                'answer'   => 'Yes — in the Certificates register, find the certificate record and click the "Email" button (envelope icon). The system generates a fresh PDF and attaches it to an email sent to the student\'s Moodle email address. The email uses a standard template that includes your RTO name and a link to the public QR code verification page. You can also download the PDF and email it manually from your own email client if you prefer.',
            ],
            [
                'question' => 'The Statement of Attainment page listed the same unit several times (once per semester) — why, and is it fixed?',
                'answer'   => 'It is fixed. Each semester intake is a separate Moodle course copy, so a student who completed the same unit across two semesters had two course completions — and the picker used to show one row per completion, keyed on the course shortname (a semester label like "DIT 20S2"). The plugin now reads the national unit code from the delivery course itself (the course ID number, or the code at the start of the course name, e.g. "TLIX5049 Determine indirect taxes") and de-duplicates by that code, so each unit appears once, keeping the most recent competent completion. A competent result always beats a non-competent one, so an earlier achievement is never hidden. If a unit still shows a semester label instead of a national code, rename that Moodle course so its name starts with the national unit code.',
            ],
            [
                'question' => 'How do the Parent category / Sub-category / Course filters work on the Issue SoA, Student Results and Certificate Hub pages?',
                'answer'   => 'They are cascading filters built from the Moodle course category tree, using the courses students actually hold results in. Pick a Parent category (the qualification), then the Sub-category list narrows to that parent\'s children (usually the intakes/semesters), then the Course list narrows to that sub-category. On Issue SoA, choosing a Parent category also auto-fills the Step 3 Qualification Code and Name because parent categories are named after the qualification they deliver. On Student Results and the Certificate Hub the cascade narrows the roster / qualification list (and every stat card) to the chosen scope. The Moodle category and course tree is the source of truth here, so tidying your categories and course names improves the filtering directly.',
            ],
            [
                'question' => 'Can I move the field settings out of the way in the certificate template editor?',
                'answer'   => 'Yes. In the editor toolbar click "Floating properties" to lift the Field properties into a movable floating toolbar (drag it by its header) so the design canvas and the live preview get the full width; click it again to dock it back. Your choice is remembered per browser. The editor also has a top action bar with Save draft and a one-click "Save & Approve" (which saves then runs the ASQA validation and submits when it passes), and the live preview sits beside the canvas so you edit on the left and see the finished PDF on the right.',
            ],
        ],
    ],

    // ── SECURITY & DATA ────────────────────────────────────────────────────
    [
        'category' => 'Security & Data Protection',
        'icon'     => 'lock',
        'faqs'     => [
            [
                'question' => 'Can an attacker delete my compliance records by sending me a crafted link?',
                'answer'   => 'No. All destructive admin actions — deleting qualifications, enrolments, units, and running any write operations — are protected with Moodle\'s session key (require_sesskey()). Even if someone sends you a URL designed to trigger a deletion, clicking it will not modify any data because the request does not carry a valid session key. This protection covers the CSRF (Cross-Site Request Forgery) vulnerability class across all admin pages in the plugin.',
            ],
            [
                'question' => 'Is student PII protected on the public certificate verification page?',
                'answer'   => 'Yes. The public QR code verification page shows only the student\'s first name and last initial — for example "Jane S." — not the full surname. The certificate number, qualification title, issue date, and RTO details are shown in full so employers can confirm authenticity. The student\'s full name, date of birth, USI, and address are never exposed on the public verification page.',
            ],
            [
                'question' => 'What is the difference between the Compliance Log and the Audit Log?',
                'answer'   => 'The Compliance Log records user-facing compliance actions — issuing a certificate, creating a student record, submitting a complaint, adding a trainer, etc. The Audit Log records security-significant administrative events — login events, bulk operations, setting changes, and data deletions. Both logs include a timestamp and the name of the Moodle user who performed the action. During an ASQA audit, both logs serve as evidence of your compliance management activities. Both logs are pruned on separate retention schedules configured in plugin settings.',
            ],
            [
                'question' => 'How long are audit logs retained?',
                'answer'   => 'By default, the Compliance Log is retained for 7 years and the Audit Log is retained for 7 years. These schedules can be changed in plugin settings (Site Administration &rarr; AI RTO Compliance &rarr; Settings &rarr; Data Retention). For compliance purposes, we recommend keeping both logs for at least 7 years — ASQA can conduct audits up to 5 years retrospectively, and some state funding agreements require 7 years.',
            ],
            [
                'question' => 'Where is compliance data stored — is it sent to any external service?',
                'answer'   => 'All compliance data (student records, certificates, trainer credentials, complaints, etc.) is stored exclusively in your Moodle site\'s database. The only outbound connections the plugin makes are: (1) training.gov.au SOAP API for qualification lookups (read-only, no student data sent); (2) the USI registry for USI verification (student\'s name and USI are sent — this is a legal requirement); (3) the AI generation features if configured (sends only the text you choose to generate against — no student PII). No compliance data is stored or sent to any Replit or third-party cloud service.',
            ],
        ],
    ],

    // ── STATE FUNDING ──────────────────────────────────────────────────────
    [
        'category' => 'State Funding',
        'icon'     => 'target',
        'faqs'     => [
            [
                'question' => 'Do I need to set up state funding if I only deliver fee-for-service training?',
                'answer'   => 'No. The state funding fields (school type, concession status, purchasing contracts) are completely optional. They only appear on student profile and enrolment forms when you have entered data in the State Funding settings page. If your RTO only delivers private fee-for-service (non-government-funded) training, leave the State Funding page blank and your forms and AVETMISS export will be unchanged.',
            ],
            [
                'question' => 'Where do I enter our QLD DTET purchasing contract codes?',
                'answer'   => 'Go to <strong>Site Administration &rarr; AI RTO Compliance &rarr; State Funding</strong> and scroll to the Queensland section. Enter your QLD RTO ID (issued by DTET — different from your national ASQA RTO code) and up to three purchasing contract codes in the format QS###### (e.g. QS102922). Once saved, these codes appear as a dropdown on enrolment forms for QLD state-funded students so staff can select the correct contract rather than typing it manually. The selected code exports to NAT00120 field 17.',
            ],
            [
                'question' => 'What are the QLD school sector codes and when do I use them?',
                'answer'   => 'School sector codes are required for school-based apprenticeship and VETiS enrolments in Queensland. The valid codes are: <strong>B01</strong> (State/government school), <strong>S01</strong> (Catholic school), <strong>QL1</strong> (QLD general), <strong>QC1</strong> (QLD Catholic sector), <strong>UC1</strong> (UCLES school), <strong>B11</strong> (Other non-government school), <strong>B02</strong> (Other government school), <strong>VE1</strong> (VET in Schools / VETiS), <strong>QNS</strong> (Not school-based). These appear on the enrolment form for QLD-funded students when the student is also flagged as "At School".',
            ],
            [
                'question' => 'What does the "School type" field on the student profile do?',
                'answer'   => 'The School type field (GOV / CAT / IND / OTH) identifies the sector of the secondary school the student currently attends. It only appears on the student profile form when the student is flagged as <em>At School</em>. It is a mandatory field for school-based apprenticeship and VETiS enrolments in both QLD (DTET) and VIC (Skills First) state funding submissions and maps to NAT00080 field 14. Leave it blank for students who are not currently at school.',
            ],
            [
                'question' => 'What do the concession status codes F, C, and E mean?',
                'answer'   => 'These codes appear on the enrolment form for state-funded students and map to NAT00120 field 15 (Concession type identifier). <strong>F &mdash; Full fee exempt:</strong> the student pays no tuition fee under a state exemption category (e.g. welfare recipient, Indigenous student under an exemption program). <strong>C &mdash; Concession card holder:</strong> the student holds a valid Health Care Card or Pensioner Concession Card and is entitled to a reduced tuition fee. <strong>E &mdash; Eligible individual:</strong> the student meets the eligibility criteria for the funded program (e.g. Smart &amp; Skilled priority cohort, Skills First target group) but does not hold a concession card. Leave blank for full-fee students.',
            ],
            [
                'question' => 'How do the NSW Smart & Skilled funding source codes (22–26) work?',
                'answer'   => 'NSW uses numeric codes for the state funding source field in NAT00120. The codes supported in the plugin are: <strong>22</strong> (Smart &amp; Skilled Standard), <strong>23</strong> (Smart &amp; Skilled Fee-Free), <strong>24</strong> (Smart &amp; Skilled Higher Skills), <strong>25</strong> (NSW other state funding), <strong>26</strong> (NSW Foundation Skills). Select the appropriate code in the NSW section of the State Funding settings page as the default for NSW-funded enrolments. Individual enrolments can override the default.',
            ],
            [
                'question' => 'What are the VIC Skills First funding source codes?',
                'answer'   => 'Victoria\'s Skills First program uses alphanumeric codes: <strong>VSKI</strong> (Skills First General), <strong>VHLS</strong> (Higher Level Skills), <strong>VLLN</strong> (Language, Literacy and Numeracy), <strong>VFFS</strong> (Fee-Free TAFE), <strong>VAPP</strong> (Apprenticeships and Traineeships), <strong>VVIS</strong> (VETiS — Victorian schools). Configure your VIC default funding source code in the VIC section of the State Funding settings page. Data is submitted to the Victorian Department of Jobs, Skills, Industry and Regions (DJSIR) via SVTS.',
            ],
            [
                'question' => 'My RTO delivers in two or more states — how do I manage different funding systems?',
                'answer'   => 'The State Funding settings page has a separate, independent section for each state and territory. Configure each state you deliver in separately — QLD RTO ID and contracts go in the QLD section, NSW codes in the NSW section, and so on. On each enrolment form, the staff member selects the funding source state code first. The form then shows only the contracts and codes applicable to that state. If a student has a QLD-funded enrolment and a separate NSW-funded enrolment, each enrolment can carry its own state-specific data.',
            ],
            [
                'question' => 'How does state funding data flow into my AVETMISS NAT export?',
                'answer'   => 'All state funding fields are stored on the student and enrolment records and are included automatically when you generate NAT files. There is no extra export step. School type exports to NAT00080 field 14. Concession status exports to NAT00120 field 15. Purchasing contract code exports to NAT00120 field 17. Funding source state code exports to NAT00120 field 18. Run the pre-export validation check in the NAT Export module to confirm all required state funding fields are populated before submitting to your State Training Authority.',
            ],
        ],
    ],

    // ── TROUBLESHOOTING ────────────────────────────────────────────────────
    [
        'category' => 'Troubleshooting',
        'icon'     => 'tool',
        'faqs'     => [
            [
                'question' => 'A trainer shows as "Credential Expired" on the dashboard — what do I do?',
                'answer'   => 'Open the Trainer Register and click on the trainer. The Credentials tab will show which credential has expired (TAE qualification, vocational competency review, or industry currency). Update the record with the renewed credential details and a new expiry date. If the trainer has not yet renewed, document the situation in the trainer\'s notes section and set a follow-up date. The dashboard tile will update to amber (upcoming expiry) or green (compliant) within the next cache refresh cycle (up to 10 minutes).',
            ],
            [
                'question' => 'The USI verification is returning an error — what does it mean?',
                'answer'   => 'The most common USI verification errors are: <strong>"USI not found"</strong> — the USI the student provided does not exist in the national registry, or it does not match their legal name. Ask the student to log in to usi.gov.au and confirm their USI and the name on their account. <strong>"Name mismatch"</strong> — the name in your student record does not exactly match the name registered against that USI (including middle names and hyphens). <strong>"Service unavailable"</strong> — the USI registry is temporarily down; try again later. Check your RTO\'s USI certificate and settings in Site Administration &rarr; AI RTO Compliance &rarr; USI Settings if errors persist.',
            ],
            [
                'question' => 'The compliance dashboard is showing stale numbers — how do I refresh it?',
                'answer'   => 'Some dashboard counts are cached by Moodle for up to 10 minutes to reduce database load. For an immediate refresh: go to Site Administration &rarr; Development &rarr; Purge all caches and click "Purge all caches". Then reload the dashboard. If counts are still wrong after a cache purge, check that the relevant scheduled task (Site Administration &rarr; Server &rarr; Scheduled tasks &rarr; Refresh compliance metrics) has run recently.',
            ],
            [
                'question' => 'The sidebar navigation is not showing some modules — how do I fix it?',
                'answer'   => 'The left-hand sidebar in the plugin is rendered by the lib.php navigation hook, which only runs when the user has the <code>local/rtocompliance:manage</code> capability. If you are logged in as a site administrator and still see missing items, try: (1) purging Moodle caches; (2) checking you are accessing the plugin from a URL under <code>/local/rtocompliance/</code> (the nav hook only activates on plugin pages); (3) confirming the plugin is installed correctly in Site Administration &rarr; Plugins &rarr; Local plugins.',
            ],
            [
                'question' => 'I upgraded the plugin and some tables seem to be missing — what happened?',
                'answer'   => 'This usually means the Moodle upgrade process did not run the plugin\'s upgrade.php script. Go to Site Administration &rarr; Notifications — if Moodle sees a version mismatch it will prompt you to run the upgrade. Click "Upgrade Moodle database now" and follow the prompts. If the notifications page shows everything is up to date but tables are still missing, contact support with the version number you upgraded from and the error message from the Moodle upgrade log.',
            ],
        ],
    ],
];
}
