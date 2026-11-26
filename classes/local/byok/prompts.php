<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace local_rtocompliance\local\byok;

/**
 * The LMS Labs service's RTO Compliance instructions, copied exactly from its source (generated, not retyped).
 *
 * @package    local_rtocompliance
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class prompts {
    /** @var string The assistant's ASQA auditor persona (assistantRoute.ts AUDITOR_PERSONA). */
    const AUDITOR_PERSONA = 'You are an elite ASQA compliance auditor and RTO subject-matter expert with deep mastery' .
        ' of:' . "\n" .
        '- The Standards for Registered Training Organisations (RTOs) 2025 and their legislative' .
        ' underpinnings' . "\n" .
        '- Every ASQA practice guide, users\' guide, and fact sheet for the 2025 Standards' . "\n" .
        '- Real audit evidence expectations, common non-compliance patterns, and corrective action' .
        ' pathways' . "\n" .
        '- How RTO management systems, training and assessment strategies, and LMS tools map to' .
        ' specific Standards' . "\n" .
        "\n" .
        'Your role is to give RTO staff the most accurate, actionable compliance guidance available' .
        ' — equivalent to having the best ASQA auditor on call.' . "\n" .
        "\n" .
        'When answering:' . "\n" .
        '1. Reason from the OUTCOME each Standard seeks (what ASQA is actually looking for and' .
        ' WHY), then explain how the plugin/system helps evidence it, and what the risk is if it is' .
        ' not addressed.' . "\n" .
        '2. Cite the specific Standard clause, practice guide section, or legislative instrument' .
        ' you are relying on (e.g. "Standard 1.1", "Practice Guide: Assessment, p.12", "Clause 8.1' .
        ' of the Standards").' . "\n" .
        '3. Clearly flag when you are QUOTING verbatim vs INFERRING from the Standards — never' .
        ' fabricate clause numbers.' . "\n" .
        '4. Be precise and practical: name the exact plugin page, setting, or report the RTO should' .
        ' use.' . "\n" .
        '5. If a question falls outside the RTO compliance domain, say so clearly rather than' .
        ' guessing.';

    /** @var string The methodology notes instruction (routes.ts, ai-methodology-suggest). */
    const METHODOLOGY = 'You are an ASQA compliance expert for Australian Registered Training Organisations (RTOs).' . "\n" .
        'Your task is to generate concise, professional "Additional Methodology Notes" text for a' .
        ' Validation Event record.' . "\n" .
        'This text appears under Standard 1.9 of the Standards for RTOs 2015 (continuous' .
        ' improvement through validation).' . "\n" .
        'Requirements:' . "\n" .
        '- 2–4 sentences of plain prose only (no bullet points, no markdown, no headings)' . "\n" .
        '- Describe HOW the selected methodologies were applied and WHAT they aimed to verify' . "\n" .
        '- Use professional Australian RTO compliance language' . "\n" .
        '- Reference how findings will support continuous improvement' . "\n" .
        '- Do not invent specific findings — keep it general and applicable';

    /** @var string[] Validation methodology codes and how the service names them (ai-methodology-suggest). */
    const METHOD_LABELS = [
        'tool_review' => 'Assessment Tool Review (checking tools against TAS/competency requirements)',
        'evidence_review' => 'Student Evidence Review (examining completed assessments)',
        'judgement_review' => 'Assessor Judgement Review (cross-marking selected assessments)',
        'mapping_check' => 'Competency Mapping Check (verifying assessment covers all UoC requirements)',
        'industry_feedback' => 'Industry Panel Feedback (industry experts reviewing the assessment approach)',
        'moderator_review' => 'External Moderation (independent assessor cross-marking)',
        'compliance_check' => 'Compliance Verification (checking against Standards for RTOs)',
        'rpl_review' => 'RPL Portfolio Review (for RPL-heavy assessments)',
        'observation_review' => 'Observation Criteria Review (reviewing practical assessment checklists)',
        'benchmarking' => 'Benchmarking (comparing with other RTOs/industry practice)',
    ];

    /** @var array[] The service's TAS_AI_FIELD_CONFIGS: field => [label, asqaGuide, systemHint, maxTokens or null]. */
    const FIELDS = [
        'scopedetails' => [
            'RTO Scope Details',
            'Standard 5.1 of the ASQA Standards 2025 requires RTOs to provide accurate, relevant and' .
                ' current information about training products including learning outcomes, qualifications' .
                ' to be issued, and the RTO\'s scope of registration. ASQA practice guide: Scope details' .
                ' should clearly state what the RTO is approved to deliver, the qualification on scope, and' .
                ' what credentials learners receive.',
            'Describe what the RTO is registered to deliver, the qualification scope on ASQA\'s' .
                ' register, the national training package it belongs to, and the credentials issued to' .
                ' successful learners.',
            null,
        ],
        'targetcohort' => [
            'Target Learner Cohort',
            'Clause 1.1 of the RTO Standards requires the TAS to document the target learner cohort' .
                ' including characteristics, prior learning, employment background, LLN levels, and access' .
                ' and equity considerations. ASQA practice guide 2024: The cohort description should' .
                ' identify who is likely to enrol, their existing skills and knowledge, workplace' .
                ' experience, language and literacy levels, and any specific support needs. This directly' .
                ' informs training design, delivery strategies, and support services.',
            'Describe who the typical learners are: their age range, employment background, prior' .
                ' qualifications, LLN (Language, Literacy and Numeracy) levels using ACSF framework,' .
                ' cultural and linguistic diversity, and any access and equity considerations specific to' .
                ' this cohort.',
            null,
        ],
        'entryrequirements' => [
            'Entry Requirements',
            'Outcome Standard 2.2 of the ASQA Standards 2025: Entry requirements must be reasonable,' .
                ' relevant to the qualification, and must not create unreasonable barriers to access. They' .
                ' must align with the AQF qualification level. ASQA practice guide: Entry requirements' .
                ' should reflect genuine prerequisites for success in the qualification. Overly restrictive' .
                ' requirements that create barriers are a compliance risk. Requirements should be' .
                ' transparent and applied consistently.',
            'List realistic entry requirements including minimum age (if applicable), prior' .
                ' qualifications or experience, minimum LLN skill level, any licensing or occupational' .
                ' requirements, technology access, and physical requirements. Justify each requirement' .
                ' against the qualification demands.',
            null,
        ],
        'llnrequirements' => [
            'LLN Requirements',
            'Standard 1.3 and ASQA TAS Practice Guide 2024: RTOs must assess learner LLN skills before' .
                ' or at enrolment and provide appropriate LLN support. The TAS must document the LLN' .
                ' demands of the qualification using the ACSF (Australian Core Skills Framework), the' .
                ' assessment instruments used to gauge learner LLN levels, and the support services' .
                ' available. ACSF levels must match the AQF qualification level (e.g. Level 3 for Cert' .
                ' III/IV, Level 4 for Diploma).',
            'Document the Language, Literacy and Numeracy (LLN) demands of this qualification using the' .
                ' ACSF framework levels. Describe the LLN pre-assessment tool used (e.g. BKSB, TAFE' .
                ' foundation skills assessment), the minimum required skill levels for learner success, and' .
                ' the LLN support services available to learners who need additional assistance.',
            null,
        ],
        'prerequisites' => [
            'Prerequisites',
            'Outcome Standard 2.2 of the ASQA Standards 2025: Prerequisites must be documented and' .
                ' justified based on genuine demands of the qualification and workplace safety/legal' .
                ' requirements. ASQA practice guide: Prerequisites should be specific, measurable and' .
                ' directly related to the qualification outcomes. They must not be used as gatekeeping' .
                ' without genuine justification. All prerequisites must be communicated to learners before' .
                ' enrolment.',
            'List any formal prerequisite qualifications, specific units, or documented workplace' .
                ' experience required for enrolment. Include the reason each prerequisite is necessary for' .
                ' learner success or safety.',
            null,
        ],
        'jobroles' => [
            'Job Roles & Outcomes',
            'Standard 1.1: The TAS must document the job roles and vocational outcomes aligned to the' .
                ' qualification, industry context, and AQF level. ASQA practice guide: Job roles should' .
                ' reflect current industry practice and labour market demand. They should be based on' .
                ' consultation with industry and demonstrate the vocational relevance of the qualification.' .
                ' This section shows how the qualification prepares learners for real employment.',
            'List the typical job roles and employment pathways graduates can pursue after completing' .
                ' this qualification. Include specific job titles, industry sectors, and progression' .
                ' pathways. Reference industry consultation findings that confirm these roles are in' .
                ' demand.',
            null,
        ],
        'deliveryschedule' => [
            'Delivery Schedule',
            'Standard 1.1 and the ASQA TAS Practice Guide: The delivery schedule must reflect the' .
                ' nominal hours, demonstrate logical sequencing, and show how units are clustered or' .
                ' sequenced to build competency progressively. ASQA auditors look for evidence that the' .
                ' schedule is realistic, accounts for foundational units before complex ones, and allows' .
                ' sufficient time for practice and assessment.',
            'Create a structured delivery schedule showing how units/modules are sequenced across the' .
                ' training period. Show any clustering arrangements (units taught together), weekly hours' .
                ' per unit, assessment weeks, and industry placement periods if applicable.',
            2000,
        ],
        'learningbreakdown' => [
            'Volume of Learning Breakdown',
            'ASQA TAS Practice Guide 2024 requires RTOs to break down the volume of learning into its' .
                ' components: structured/supervised training, workplace-based learning, self-directed' .
                ' study, and assessment activities. The total must align with the TGA nominal hours and AQF' .
                ' volume of learning guidelines for the qualification level.',
            'Break down the total volume of learning into: structured training (face-to-face or' .
                ' synchronous online), workplace-based learning hours, self-directed study (asynchronous),' .
                ' and assessment activities. Show totals and verify they align with AQF expectations for' .
                ' the qualification level.',
            null,
        ],
        'volumejustification' => [
            'Volume of Learning Justification',
            'Standard 1.1 and the ASQA TAS Practice Guide: RTOs must justify how their planned volume' .
                ' of learning is sufficient for learners to develop the required competencies.' .
                ' Under-delivery relative to nominal hours is a frequently identified ASQA audit risk. The' .
                ' justification must show the link between the volume of learning, the qualification' .
                ' complexity, and the learner cohort characteristics.',
            'Justify why the planned volume of learning is sufficient to develop competency in this' .
                ' qualification. Reference the AQF volume of learning guidelines, the TGA nominal hours,' .
                ' the qualification complexity, learner cohort experience, and delivery mode. Explain how' .
                ' each component of the learning volume contributes to competency development.',
            null,
        ],
        'assessmentmethods' => [
            'Assessment Methods',
            'Standard 1.8 of the ASQA Standards 2025: Assessment must be valid, reliable, flexible and' .
                ' fair. RTOs must use a range of assessment methods appropriate to the units\' performance' .
                ' evidence, knowledge evidence, and assessment conditions. Assessment must reflect real' .
                ' workplace conditions where possible. ASQA practice guide: Assessment methods must gather' .
                ' sufficient evidence across all aspects of competency — skills, knowledge, and the ability' .
                ' to apply both in context.',
            'Describe the range of assessment methods used across this qualification. For each major' .
                ' unit or cluster, specify the evidence types gathered (e.g. direct observation, portfolio' .
                ' of workplace evidence, written knowledge questions, projects, role plays, case studies,' .
                ' third-party reports). Explain how the methods ensure valid and reliable assessment of' .
                ' competency.',
            null,
        ],
        'assessmentmapping' => [
            'Assessment Mapping to Units',
            'Standard 1.8: All assessment tools must be mapped to the performance criteria, performance' .
                ' evidence, knowledge evidence, and assessment conditions for each unit. ASQA auditors' .
                ' scrutinise assessment mapping to verify that all unit requirements are assessed. Mapping' .
                ' must be auditable and show clear alignment between evidence gathered and unit' .
                ' requirements.',
            'Describe how assessment tasks are mapped to unit requirements. Explain the mapping process' .
                ' used, how performance criteria, performance evidence, and knowledge evidence are' .
                ' addressed by the assessment tools, and how the mapping is documented in the assessment' .
                ' tool folder.',
            null,
        ],
        'validationschedule' => [
            'Assessment Validation Schedule',
            'Standard 1.9 of the ASQA Standards 2025: RTOs must validate assessment tools at least once' .
                ' every 5 years for each unit, and more frequently for high-risk or new qualifications.' .
                ' Validation must involve industry representatives. ASQA practice guide: The validation' .
                ' schedule should show who will validate (including industry validators), when validation' .
                ' will occur, how findings will be documented, and how improvements will be implemented and' .
                ' tracked.',
            'Create a validation schedule showing: upcoming validation cycles for each unit or cluster,' .
                ' the validators involved (including industry representatives), the validation method used' .
                ' (e.g. moderation, benchmarking), the documentation process, and how continuous' .
                ' improvement actions from validation are tracked and implemented.',
            null,
        ],
        'trainerrequirements' => [
            'Trainer/Assessor Requirements',
            'Standards 1.13–1.16 of the ASQA Standards 2025: Trainers must hold a TAE qualification' .
                ' (TAE40116 or TAE40122, or approved equivalent), maintain current vocational competency' .
                ' at/above the level being delivered, and demonstrate ongoing industry currency through' .
                ' professional development and industry engagement. The TAS must document how these' .
                ' requirements are demonstrated and maintained.',
            'Describe the required qualifications, vocational experience, and TAE credentials for' .
                ' trainers delivering this qualification. Include: required TAE credential, required' .
                ' vocational qualifications (level and currency), minimum years of industry experience,' .
                ' ongoing currency requirements (CPD, industry engagement), and how currency evidence is' .
                ' collected and maintained.',
            null,
        ],
        'supervisionarrangements' => [
            'Supervision Arrangements',
            'Where contractors, third-party trainers, or workplace supervisors are used, Standard 2.3' .
                ' of the RTO Standards requires RTOs to document supervision arrangements and monitor' .
                ' quality of delivery. The RTO retains responsibility for the quality of training and' .
                ' assessment outcomes regardless of who delivers it.',
            'Describe any supervision arrangements for trainers or assessors including: supervision' .
                ' ratios for workplace-based training, how contracted trainers are monitored, how workplace' .
                ' supervisors are briefed and supported, quality monitoring processes, and how supervision' .
                ' records are maintained.',
            null,
        ],
        'learningresources' => [
            'Learning Resources & Materials',
            'Standard 1.8 of the ASQA Standards 2025: RTOs must have adequate learning resources that' .
                ' are current, accessible, culturally appropriate, and reflect the delivery mode. Resources' .
                ' must support learners to meet all unit requirements and reflect current industry' .
                ' practice. ASQA practice guide: Learning resources should be reviewed regularly to ensure' .
                ' currency with industry standards and training package updates.',
            'List the learning resources and materials used: textbooks (with edition and year),' .
                ' e-learning modules, learning management system, handouts, industry reference materials,' .
                ' workplace documents used as learning resources, and any tools/equipment available for' .
                ' practice. Confirm all resources are current and accessible in the chosen delivery mode.',
            null,
        ],
        'facilities' => [
            'Facilities & Equipment',
            'Standard 1.8 of the ASQA Standards 2025: Facilities and equipment must be adequate,' .
                ' well-maintained, safe, and simulate industry conditions where assessment conditions' .
                ' require it. ASQA practice guide: Facilities must meet the specific assessment conditions' .
                ' stated in training package units. For example, if a unit requires assessment in a "real' .
                ' or simulated workplace", the facility must credibly simulate the actual workplace' .
                ' environment.',
            'Describe the facilities and equipment available to deliver and assess this qualification:' .
                ' classroom/workshop facilities, specialist equipment, simulated work environments,' .
                ' software/hardware, safety equipment, and consumables. Confirm that the facilities meet' .
                ' the assessment conditions specified in the training package units.',
            null,
        ],
        'technology' => [
            'Technology Requirements',
            'Standard 1.8 of the ASQA Standards 2025: Technology used for delivery and assessment must' .
                ' be appropriate to the learning outcomes, accessible to the learner cohort, and' .
                ' technically supported. For online or blended delivery, RTOs must ensure learners have' .
                ' access to required technology and provide technical support. ASQA practice guide:' .
                ' Technology requirements should be clearly communicated to learners before enrolment.',
            'Describe technology requirements for learners and trainers: hardware (computer' .
                ' specifications, mobile devices), software (LMS access, industry-specific software),' .
                ' internet connectivity requirements, accessibility tools, and the technical support' .
                ' available to learners who experience difficulties.',
            null,
        ],
        'thirdparty' => [
            'Third-Party Arrangements',
            'Standard 2.3 of the RTO Standards: Where RTOs engage third parties for training or' .
                ' assessment, they must have written agreements and systematically monitor third-party' .
                ' quality. The RTO retains legal responsibility for compliance. ASQA practice guide:' .
                ' Third-party agreements must specify the scope of the arrangement, quality requirements,' .
                ' monitoring processes, and the RTO\'s intervention rights if quality issues arise.',
            'Describe any third-party arrangements for delivery or assessment: the organisation' .
                ' involved, the scope of their role, the written agreement in place, quality monitoring' .
                ' processes (e.g. observations, moderation, reporting), and how the RTO ensures compliance' .
                ' standards are maintained through the third party.',
            null,
        ],
        'learnersupport' => [
            'Learner Support Services',
            'Standard 1.7 of the ASQA Standards 2025: RTOs must identify individual learner support' .
                ' needs at enrolment and throughout training, and provide appropriate support. ASQA' .
                ' practice guide: Support services should address LLN needs, disability and learning' .
                ' support, cultural and linguistic diversity, wellbeing support, and career guidance. The' .
                ' TAS must document how learners are informed about and can access available services.',
            'Describe the learner support services available: LLN support (what tools/programs, how' .
                ' accessed), learning assistance (tutoring, mentoring), cultural and linguistic support,' .
                ' disability support (reasonable adjustments process), wellbeing and counselling referrals,' .
                ' and technology support. Explain how learners are identified as needing support and how' .
                ' they access services.',
            null,
        ],
        'accessibility' => [
            'Accessibility & Reasonable Adjustments',
            'Standard 1.7 and applicable anti-discrimination legislation (Disability Discrimination Act' .
                ' 1992): RTOs must provide reasonable adjustments to training and assessment for learners' .
                ' with disability or other barriers. ASQA practice guide: Adjustments must not compromise' .
                ' the integrity of the competency standards — they change HOW evidence is gathered, not' .
                ' WHAT evidence is required. The process must be documented, fair, and consistently' .
                ' applied.',
            'Describe the reasonable adjustment process: how learners request adjustments, who assesses' .
                ' adjustment requests, examples of adjustments typically made (e.g. extended time,' .
                ' alternative formats, assistive technology, oral instead of written assessment), how' .
                ' adjustments are documented, and how the integrity of the competency standard is' .
                ' maintained.',
            null,
        ],
        'marketinginfo' => [
            'Pre-Enrolment Information',
            'Standard 5.2 of the RTO Standards: RTOs must provide accurate, current, and relevant' .
                ' pre-enrolment information including course details, fees, refund policies, entry' .
                ' requirements, expected outcomes, and complaints processes. ASQA practice guide:' .
                ' Misleading marketing or failure to disclose material information before enrolment is a' .
                ' significant compliance risk. Information must be provided before the learner commits.',
            'Describe the pre-enrolment information provided to prospective learners: course overview' .
                ' and outcomes, duration and delivery mode, qualification issued, entry requirements, fees' .
                ' and refund policy, expected workload, equipment or technology needs, learner support' .
                ' available, and how to make enquiries or complaints. Note where and how this information' .
                ' is provided.',
            null,
        ],
        'feesinformation' => [
            'Fees & Payment Information',
            'Standard 5.3 and the Tuition Protection Service (TPS) requirements: RTOs must clearly' .
                ' communicate all fees, payment schedules, refund policies, and any additional costs before' .
                ' enrolment. ASQA practice guide: Fee information must be transparent, complete, and free' .
                ' from hidden costs. The refund policy must be fair and accessible. Fee protection' .
                ' obligations apply for pre-paid fees over $1,500.',
            'Describe the complete fee structure: total course fee, payment schedule options (upfront,' .
                ' instalment, payment plan), additional costs (materials, equipment, registration fees),' .
                ' RPL fees, fee protection arrangements for pre-paid fees, and the refund/withdrawal policy' .
                ' including timeframes and conditions.',
            null,
        ],
        'placementdetails' => [
            'Placement Details & Supervision',
            'Outcome Standards 1.1(2e); 1.2; 2.1(2c(iv)) of the ASQA Standards 2025: Work placement' .
                ' must be structured, supervised, and assessed. The TAS must document placement hours, host' .
                ' employer agreements, workplace supervisor requirements, and the quality assurance' .
                ' processes. ASQA practice guide: Work placement must provide authentic industry experience' .
                ' aligned to the unit assessment conditions. Learner safety and supervision adequacy are' .
                ' key audit focuses.',
            'Check the context for hasworkplacement and placementhours values. If hasworkplacement is 0' .
                ' or placementhours is 0 or not set, write a statement that no mandatory work placement is' .
                ' required for this qualification and that practical competency is developed through' .
                ' simulated workplace activities (e.g. engineering workshops, clinical simulations,' .
                ' supervised role-plays). If work placement IS required, reference the actual placement' .
                ' hours from the context (e.g. "Students must complete X hours of supervised work' .
                ' placement"). Either way: reference Outcome Standards 1.1(2e); 1.2; 2.1(2c(iv)), describe' .
                ' host employer agreement requirements, workplace supervisor qualifications and briefing' .
                ' process, learner-to-supervisor ratios, monitoring and support during placement (RTO' .
                ' visits/check-ins), how placement evidence is recorded, and the process for managing' .
                ' unsafe or unsuitable placements.',
            null,
        ],
        'transitionplan' => [
            'Transition / Teach-Out Procedures',
            'Standard 1.12 of the ASQA Standards 2025: When a training product is superseded or removed' .
                ' from scope, RTOs must have documented teach-out or transition plans to ensure enrolled' .
                ' learners can complete their qualification or transition to the current equivalent. ASQA' .
                ' practice guide: Learners must not be disadvantaged by qualification transitions. Options' .
                ' and timelines must be clearly communicated.',
            'Describe the teach-out and transition procedures: the date the qualification was or will' .
                ' be superseded, the teach-out end date, the equivalent current qualification, how enrolled' .
                ' learners are notified of options, the transition pathway available (credit transfer,' .
                ' recognition of current learning), the timeline for completing under the superseded' .
                ' qualification, and the process for learners who cannot complete within the teach-out' .
                ' period.',
            null,
        ],
        'riskmanagement' => [
            'Training Delivery Risks & Mitigation',
            'Standard 4 (Governance and Administration) of the ASQA Standards 2025: RTOs must have a' .
                ' systematic risk management approach. ASQA practice guide: Risk management for training' .
                ' delivery should identify operational risks (trainer unavailability, facility issues),' .
                ' learner risks (non-completion, welfare), compliance risks (assessment validity,' .
                ' currency), and financial risks. Each risk should have an assessed likelihood, impact, and' .
                ' documented mitigation strategy.',
            'Identify the key risks to training delivery for this qualification and document mitigation' .
                ' strategies. Include: trainer/assessor unavailability, resource or facility failures, low' .
                ' enrolment, assessment validity issues, third-party arrangement failures, learner welfare' .
                ' issues, and qualification currency risk. Rate each risk for likelihood and impact, and' .
                ' specify the mitigation controls in place.',
            null,
        ],
        'complaintsprocess' => [
            'Complaints & Appeals Reference',
            'Standard 6 of the ASQA Standards 2025: RTOs must have a documented, accessible, and fair' .
                ' complaints and appeals process. ASQA practice guide: The process must be communicated to' .
                ' learners before or at enrolment. Assessment appeals must provide a genuine opportunity' .
                ' for review. Outcomes must be recorded and used for continuous improvement. Learners must' .
                ' be able to escalate to ASQA or the relevant state authority if not satisfied.',
            'Reference the RTO\'s complaints and appeals policy. Describe: how learners can lodge a' .
                ' complaint or appeal (internal process), the timeline for response, how decisions are made' .
                ' (independent review), the escalation process if unsatisfied (ASQA, state training' .
                ' authority), how outcomes are recorded, and how findings feed into continuous improvement.',
            null,
        ],
        'continuousimprovement' => [
            'Feedback & Review Mechanisms',
            'Standard 2.2 of the Governance Standards: RTOs must systematically collect and act on' .
                ' feedback from learners, employers, and trainers/assessors to continuously improve' .
                ' training and assessment outcomes. ASQA practice guide: Quality Indicator data (learner' .
                ' engagement, employer satisfaction, competency completion) is mandatory. The continuous' .
                ' improvement cycle must show evidence of collecting feedback, analysing findings,' .
                ' implementing improvements, and evaluating effectiveness.',
            'Describe the continuous improvement mechanisms: learner feedback surveys (when, how, how' .
                ' results are used), employer satisfaction surveys, trainer/assessor feedback mechanisms,' .
                ' Quality Indicator data collection and reporting, assessment validation findings and their' .
                ' implementation, and how the continuous improvement register is maintained. Explain how' .
                ' the improvement cycle closes the loop from feedback to action to evaluation.',
            null,
        ],
        'vocationalqualifications' => [
            'Vocational Qualifications',
            'Standard 3.3(2) of the Standards for RTOs 2025: Trainer/assessors must hold vocational' .
                ' qualifications relevant to the training products they deliver and assess, at or above the' .
                ' AQF level being delivered.',
            'List the trainer\'s vocational qualifications relevant to delivering this training product:' .
                ' qualification code, title, year completed, and issuing RTO.',
            null,
        ],
        'vocationalcompetencynotes' => [
            'Vocational Competency Notes',
            'Standard 3.3(2) of the Standards for RTOs 2025: Current vocational competency means skills' .
                ' and knowledge in the industry that are current and relevant to the training and' .
                ' assessment context. Notes should document how the trainer maintains and demonstrates' .
                ' currency.',
            'Document the trainer\'s current vocational competency in the relevant industry: recent work' .
                ' experience, industry engagement activities (conferences, employer visits, guest' .
                ' lectures), current membership of industry bodies, and any recent industry projects' .
                ' undertaken.',
            null,
        ],
        'riskdescription' => [
            'Risk Description',
            'Standard 8: Risk descriptions should clearly identify the specific risk event, its source,' .
                ' and the conditions under which it may occur.',
            'Clearly describe the specific compliance or operational risk: what could go wrong, under' .
                ' what circumstances, who or what is affected, and the potential consequences for RTO' .
                ' operations or compliance.',
            null,
        ],
        'mitigationplan' => [
            'Mitigation Plan',
            'Standard 8: Mitigation plans should specify concrete, measurable control actions that' .
                ' reduce the likelihood or impact of the identified risk.',
            'Describe specific mitigation actions: preventive controls that reduce likelihood,' .
                ' corrective controls that reduce impact, who is responsible for each control, how' .
                ' effectiveness is monitored, and contingency plans if the risk eventuates.',
            null,
        ],
        'evidencedescription' => [
            'RPL Evidence Description',
            'Standard 1.8: RPL assessment must be based on quality evidence that demonstrates the' .
                ' candidate\'s current competency. Evidence must be valid (relevant), sufficient (enough to' .
                ' demonstrate competency), authentic (the candidate\'s own), and current (reflects current' .
                ' skills).',
            'Describe the RPL evidence provided: types of evidence (e.g. work samples, third-party' .
                ' reports, licences, certificates, demonstration), the quality of the evidence (currency,' .
                ' sufficiency, authenticity), and how the evidence maps to the unit requirements being' .
                ' RPL\'d.',
            null,
        ],
        'decisionreason' => [
            'RPL Decision Reason',
            'Standard 1.8: RPL decisions must be documented, transparent, and based on the sufficiency' .
                ' and quality of evidence against all elements of competency. The decision reason must show' .
                ' how the evidence supports the outcome.',
            'Document the RPL assessment decision: the overall outcome (granted/not granted), the' .
                ' specific units assessed, how the evidence demonstrated (or failed to demonstrate) each' .
                ' element of competency, any gaps identified, and any additional assessment required.',
            null,
        ],
        'scopenotes' => [
            'Approved Delivery Scope',
            'Standard 3.3(2) of the Standards for RTOs 2025: Each trainer and assessor must only' .
                ' deliver and assess within their approved scope — the specific qualifications, skill sets,' .
                ' and units they are approved to deliver based on their vocational competency and industry' .
                ' currency. ASQA auditors verify that trainer delivery scope is documented, current, and' .
                ' matches the units being actively delivered.',
            'List the specific qualifications, skill sets, and/or individual units of competency this' .
                ' trainer is approved to deliver and/or assess. Use the national training package code' .
                ' format (e.g. TAE40122, BSB50320). Include any conditions, restrictions, or endorsements' .
                ' on the approved scope. Confirm the scope reflects the trainer\'s documented vocational' .
                ' competency and current industry knowledge.',
            null,
        ],
        'notes' => [
            'Notes',
            'General compliance documentation notes should be specific, factual, and directly relevant' .
                ' to the ASQA standard or requirement being recorded. ASQA practice guidance emphasises' .
                ' contemporaneous, accurate record-keeping that provides an auditable evidence trail. Notes' .
                ' attached to trainer records, enrolments, or compliance activities should state the action' .
                ' taken, the basis for the decision, and any follow-up required.',
            'Write a concise, professional compliance note relevant to the context (trainer' .
                ' credentials, student enrolment, risk item, or RPL assessment). The note should state the' .
                ' specific fact, decision, or action being documented; reference the applicable ASQA' .
                ' standard or RTO policy if relevant; and identify any follow-up actions, review dates, or' .
                ' conditions that apply.',
            null,
        ],
        'assessmentnotes' => [
            'Assessment Plan Notes',
            'Outcome Standards 1.3-1.4 of the ASQA Standards 2025 (ASQA TAS Practice Guide 2024):' .
                ' Assessment plan notes should document the purpose, scope, and approach of each assessment' .
                ' event. They should clarify how assessment conditions reflect realistic workplace' .
                ' scenarios, how reasonable adjustment is applied, and how multiple assessors ensure' .
                ' consistency of judgement. Notes should confirm the assessment plan aligns with the' .
                ' Training Package requirements and that all elements of competency, performance criteria,' .
                ' and knowledge evidence are addressed across the assessment events. ASQA auditors review' .
                ' assessment plan notes to verify assessment design is appropriate, fair, flexible, and' .
                ' valid.',
            'Write detailed notes for this assessment plan entry. Cover: the assessment purpose' .
                ' (formative or summative), the specific competency elements and performance criteria being' .
                ' assessed in this event, the conditions and environment (simulated or real workplace),' .
                ' timing and duration, how reasonable adjustment provisions are applied without' .
                ' compromising competency standards, how this assessment contributes to the overall' .
                ' competency determination, and any specific resources or tools required. Reference the' .
                ' relevant unit of competency code where applicable.',
            null,
        ],
        'consult_feedback' => [
            'Industry Consultation Key Feedback',
            'ASQA Standards 2025 clauses 1.4 and 4.1 require RTOs to document systematic industry' .
                ' engagement and demonstrate how engagement findings inform training and assessment design.' .
                ' The Key Feedback summary must capture the industry representative\'s perspective, the' .
                ' themes raised, and how those themes connect to the qualification\'s target job roles and' .
                ' current industry practice.',
            'Write a concise 3-5 sentence summary of the key feedback the industry representative' .
                ' provided during the consultation. Reflect their perspective in the third person,' .
                ' reference the feedback themes or topics they identified, and explain how the feedback' .
                ' connects to job-readiness outcomes for the qualification\'s target roles. Australian' .
                ' English, professional tone, no placeholders, 80-150 words.',
            null,
        ],
        'consult_impact_training' => [
            'Industry Consultation Impact on Training Delivery',
            'ASQA Standards 2025 clauses 1.4, 1.5 and 4.1 require RTOs to demonstrate that industry' .
                ' consultation findings are systematically used to review and improve training delivery' .
                ' strategies. The TAS must document specific changes or validated approaches made to' .
                ' delivery methods as a direct result of the industry feedback received.',
            'Write a concise 3-5 sentence statement explaining how the industry consultation feedback' .
                ' will be incorporated into training delivery. Be specific about the delivery strategies' .
                ' selected (e.g. scenario-based learning, workplace simulations, guest industry speakers,' .
                ' work placement) and tie each strategy to a unit-of-competency outcome or learner cohort' .
                ' need. Australian English, future-tense action voice ("the RTO will…"), no placeholders,' .
                ' 80-150 words.',
            null,
        ],
        'consult_impact_assessment' => [
            'Industry Consultation Impact on Assessment Design',
            'ASQA Standards 2025 clauses 1.8, 1.12 and 4.1 require that assessment design reflects' .
                ' current industry practice and incorporates feedback from industry consultation. The TAS' .
                ' must document how industry input has shaped or validated assessment methods, conditions,' .
                ' and tools to ensure assessments reflect authentic workplace requirements.',
            'Write a concise 3-5 sentence statement explaining how the industry consultation feedback' .
                ' will be incorporated into assessment design. Be specific about the assessment strategies' .
                ' selected (e.g. workplace observation, third-party reports, portfolios, projects,' .
                ' simulated workplace tasks) and the principles of assessment (validity, reliability,' .
                ' fairness, flexibility) they reinforce. Australian English, future-tense action voice, no' .
                ' placeholders, 80-150 words.',
            null,
        ],
        'voccomp_description' => [
            'Description of Vocational Practice',
            'Standard 3.3(2) of the Standards for RTOs 2025 requires trainers to demonstrate current' .
                ' vocational competency through documented evidence. The Description of Vocational Practice' .
                ' must explain what skills were applied or maintained through the recorded activity and how' .
                ' those skills directly relate to the units of competency in the qualification being' .
                ' delivered.',
            'Write a concise 3–5 sentence description of the vocational practice activity. Explain what' .
                ' vocational skills were applied or maintained through this activity and how they directly' .
                ' relate to the units of competency in the qualification being delivered. Australian' .
                ' English, objective professional tone (no first-person pronouns, no placeholders), 80–150' .
                ' words.',
            null,
        ],
        'revisionnotes' => [
            'Revision Notes',
            'ASQA Standard 1.1 requires RTOs to maintain current, version-controlled TAS documents.' .
                ' Revision notes must record the nature of changes made, the rationale for each change' .
                ' (e.g. updated industry consultation findings, regulatory changes, validation outcomes,' .
                ' new delivery modes), the date of revision, and the person responsible. ASQA auditors use' .
                ' revision history to verify that the TAS is regularly reviewed and updated in response to' .
                ' industry feedback, regulatory changes, and continuous improvement processes.',
            'Write concise revision notes recording: the date of this revision, the reason for the' .
                ' change (e.g. updated industry consultation findings, revised assessment mapping, new' .
                ' delivery mode added, regulatory update, validation outcome), the specific sections or' .
                ' fields changed, and the name/role of the person who made the revision. Professional' .
                ' Australian English, factual and specific — avoid vague statements. 60-120 words.',
            1200,
        ],
        'complaint_resolution' => [
            'Complaint Resolution Statement',
            'Standard 6 of the ASQA Standards for RTOs 2025 requires RTOs to have documented, fair, and' .
                ' accessible complaints and resolution processes. Resolution statements must document what' .
                ' was investigated, key findings, actions taken, outcomes, and any systemic improvements' .
                ' identified.',
            'Write a professional complaint resolution statement (150-250 words). Include: what was' .
                ' investigated, key findings, actions taken to resolve the complaint, the outcome, and any' .
                ' systemic improvements identified. Professional Australian English, compliant with ASQA' .
                ' Standards Clause 6. No placeholders.',
            800,
        ],
        'appeal_resolution' => [
            'Appeal Outcome Reason',
            'Standard 6.2 of the ASQA Standards for RTOs 2025 requires RTOs to document appeal outcomes' .
                ' with clear rationale. Outcome reasons must include key findings, analysis of grounds, how' .
                ' the decision was reached, and the rationale for the outcome, consistent with natural' .
                ' justice principles.',
            'Write a professional appeal outcome reason (150-250 words). Include: key findings from the' .
                ' appeal panel, analysis of the grounds for appeal, how the decision was reached, and the' .
                ' rationale for the outcome. Consistent with ASQA Standards and natural justice principles.' .
                ' No placeholders.',
            800,
        ],
        'appeal_grounds' => [
            'Grounds for Appeal',
            'Standard 6.2 of the ASQA Standards 2025 and natural justice principles: Grounds for appeal' .
                ' must clearly state what decision is being appealed, the specific reasons the appellant' .
                ' believes the decision was incorrect or unfair, and the outcome being sought. Appeals' .
                ' should reference relevant RTO policies and ASQA Standards where applicable.',
            'Write professional grounds for appeal (150-250 words). Include: a clear statement of what' .
                ' decision is being appealed, specific reasons the decision was incorrect or unfair,' .
                ' reference to relevant ASQA Standards for RTOs 2025 or RTO policies, and the outcome being' .
                ' sought. Structured and formal. No placeholders.',
            800,
        ],
    ];
}
